<?php
// Step C3 of docs/exapp-clustering.md: ExAppClusteringClient against the dev ExApp. Runs inside the Nextcloud container:
//   php exapp_client.php USER        the cache sync, a stale entry, the 409 retry, a face that cannot be synced
//   php exapp_client.php USER down   with the ExApp stopped: a transport failure must throw
// Prints "check"/"info" lines for php_checks (dev/lib.sh). Writes nothing to Nextcloud's database; empties the ExApp's
// cache at the start and leaves it filled with USER's pool.
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';

use OCA\AppAPI\PublicFunctions;
use OCA\FacewrightBridge\Service\ClusteringService;
use OCA\FacewrightBridge\Service\ExAppClusteringClient;
use OCA\FacewrightBridge\Service\ExAppClusteringException;

[$user, $mode] = [$argv[1], $argv[2] ?? 'up'];
$client = \OCP\Server::get(ExAppClusteringClient::class);
$appAPI = \OCP\Server::get(PublicFunctions::class);
$db = \OCP\Server::get(\OCP\IDBConnection::class);
$check = function (string $label, $expected, $actual): void {
	echo 'check ' . json_encode($label) . ' ' . json_encode($expected) . ' ' . json_encode($actual) . "\n";
};
$direct = function (string $route, array $body) use ($appAPI, $user): array {
	$r = $appAPI->exAppRequest('facewright', $route, $user, 'POST', $body, ['timeout' => 120]);
	return ['status' => $r instanceof \OCP\Http\Client\IResponse ? $r->getStatusCode() : 0, 'body' => $r instanceof \OCP\Http\Client\IResponse ? json_decode((string)$r->getBody(), true) : $r];
};

if ($mode === 'down') {
	$thrown = 'nothing';
	try {
		// A face that exists, so the sync reaches the ExApp (an id with no row sends no request at all).
		$faceId = (int)$db->executeQuery('SELECT MIN(id) FROM ' . $db->getQueryBuilder()->getTableName('facewright_faces'))->fetchOne();
		$client->syncCache($user, [$faceId]);
	} catch (ExAppClusteringException $e) {
		$thrown = 'ExAppClusteringException';
		echo 'info ' . $e->getMessage() . "\n";
	}
	$check('ExApp down: syncCache throws', 'ExAppClusteringException', $thrown);
	exit;
}

$pool = \OCP\Server::get(ClusteringService::class)->loadPoolForComparison($user, true);
$faceIds = array_values(array_unique($pool['faceIds']));
echo 'info pool ' . count($pool['detections']) . ' rows, ' . count($faceIds) . ' faces, ' . count($pool['pendingIds']) . " to attempt\n";

// 1. Checksums: the SQL CRC32 equals PHP's crc32() of the blob as read.
$crcs = $client->checksums($faceIds);
$qb = $db->getQueryBuilder();
$qb->select('id', 'embedding')->from('facewright_faces')
	->where($qb->expr()->in('id', $qb->createNamedParameter(array_slice($faceIds, 0, 1000), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
$differ = 0;
$blobs = [];
$res = $qb->executeQuery();
while ($row = $res->fetch()) {
	$blob = is_resource($row['embedding']) ? stream_get_contents($row['embedding']) : (string)$row['embedding'];
	$blobs[(int)$row['id']] = $blob;
	$differ += (crc32($blob) !== $crcs[(int)$row['id']]) ? 1 : 0;
}
$res->closeCursor();
$check('checksums: one per face', count($faceIds), count($crcs));
$check('checksums: SQL CRC32 = crc32() of the blob (first 1000, differing)', 0, $differ);

// 2. Cold sync uploads everything; a warm one nothing.
$check('cache cleared', 200, $direct('/cluster/cache/clear', [])['status']);
$client->takeStats();
$cold = $client->syncCache($user, $faceIds, $crcs);
$stats = $client->takeStats();
echo "info cold sync: {$stats['requests']} requests, " . round($stats['bytesSent'] / 1048576, 1) . " MB, {$stats['ms']} ms\n";
$check('cold sync uploads every face', ['checked' => count($faceIds), 'uploaded' => count($faceIds)], $cold);
$warm = $client->syncCache($user, $faceIds, $crcs);
$stats = $client->takeStats();
echo "info warm sync: {$stats['requests']} requests, {$stats['ms']} ms\n";
$check('warm sync uploads nothing', ['checked' => count($faceIds), 'uploaded' => 0], $warm);

// 3. A stale entry (the cache holds another embedding under a face's id, as after a re-detection) is replaced.
[$a, $b] = array_slice(array_keys($blobs), 0, 2);
$put = $direct('/cluster/cache/put', ['faces' => [['id' => $a, 'crc' => crc32($blobs[$b]), 'embedding' => base64_encode($blobs[$b])]]]);
$check('planted a stale entry', 200, $put['status']);
$check('sync replaces exactly the stale entry', ['checked' => count($faceIds), 'uploaded' => 1], $client->syncCache($user, $faceIds, $crcs));

// 4. The 409 path: the cache is emptied between the sync and /cluster; the client must sync and retry once.
$rows = [];
foreach ($pool['detections'] as $id => $d) {
	$rows[] = [$id, $pool['faceIds'][$id], $crcs[$pool['faceIds'][$id]], $d['cluster_id']];
}
$attempt = array_slice($pool['pendingIds'], 0, 300);
$direct('/cluster/cache/clear', []);
$client->takeStats();
$answer = null;
try {
	$answer = $client->cluster($user, $rows, $attempt, 0.5, 3);
} catch (ExAppClusteringException $e) {
	echo 'info ' . $e->getMessage() . "\n";
}
$stats = $client->takeStats();
echo "info /cluster after a cleared cache: {$stats['requests']} requests, {$stats['ms']} ms" . ($answer ? ", compute {$answer['compute_ms']} ms" : '') . "\n";
$check('/cluster recovers from a cleared cache', count($attempt), $answer === null ? null : count($answer['decisions']));
$check('the recovery took one 409, a sync and one retry (more than 3 requests)', true, $stats['requests'] > 3);

// 5. A face that cannot be synced (no such row) is an error, not a loop.
$bogus = $rows;
$bogus[] = [PHP_INT_MAX - 1, 999999999, 12345, null];
$thrown = 'nothing';
try {
	$client->cluster($user, $bogus, $attempt, 0.5, 3);
} catch (ExAppClusteringException $e) {
	$thrown = 'ExAppClusteringException';
	echo 'info ' . $e->getMessage() . "\n";
}
$check('a face that cannot be synced throws after one retry', 'ExAppClusteringException', $thrown);
