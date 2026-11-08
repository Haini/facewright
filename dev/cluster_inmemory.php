<?php
// Step C2 of docs/exapp-clustering.md: the comparison's view of PHP clustering writes nothing, and a temporary label
// can never reach the database. Runs inside the Nextcloud container:  php cluster_inmemory.php USER
// Prints "check <label> <expected> <actual>" lines; dev/cluster-inmemory.sh compares them.
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';

use OCA\FacewrightBridge\Service\ClusteringService;

$user = $argv[1];
$db = \OCP\Server::get(\OCP\IDBConnection::class);
$clustering = \OCP\Server::get(ClusteringService::class);
$tables = ['facewright_user_faces', 'facewright_clusters', 'recognize_face_detections', 'recognize_face_clusters', 'jobs'];
$fingerprint = function () use ($db, $tables): string {
	$out = [];
	foreach ($tables as $t) {
		$row = $db->executeQuery('CHECKSUM TABLE ' . $db->getQueryBuilder()->getTableName($t))->fetch();
		$count = $db->executeQuery('SELECT COUNT(*) FROM ' . $db->getQueryBuilder()->getTableName($t))->fetchOne();
		$out[] = "$t:$count:" . $row['Checksum'];
	}
	return implode(' ', $out);
};
$check = function (string $label, $expected, $actual): void {
	echo 'check ' . json_encode($label) . ' ' . json_encode($expected) . ' ' . json_encode($actual) . "\n";
};

$before = $fingerprint();

$plain = $clustering->loadPoolForComparison($user, false);
$reopened = $clustering->loadPoolForComparison($user, true);
$unclusterable = (int)$db->executeQuery(
	'SELECT COUNT(*) FROM ' . $db->getQueryBuilder()->getTableName('facewright_user_faces') . ' WHERE user_id = ? AND state = 3', [$user]
)->fetchOne();
echo "info pool " . count($plain['detections']) . ", pending " . count($plain['pendingIds']) . ", unclusterable $unclusterable\n";
$check('reopen adds exactly the unclusterable rows', count($plain['pendingIds']) + $unclusterable, count($reopened['pendingIds']));
$sorted = $reopened['pendingIds'];
sort($sorted);
$check('pendingIds ascending, no duplicates', true, $sorted === $reopened['pendingIds'] && count(array_unique($sorted)) === count($sorted));
$check('faceIds for every pool row', array_keys($plain['detections']), array_keys($plain['faceIds']));

$detections = $reopened['detections'];
$next = 0;
$stats = $clustering->assignInMemory($detections, $reopened['pendingIds'], 0.5, 3, function () use (&$next): int { return --$next; });
echo "info in memory: {$stats['newClusters']} new, {$stats['joined']} joined, {$stats['skipped']} skipped\n";
$check('temporary labels are the ones handed out', $next === 0 ? [] : range(-1, $next), $stats['createdClusterIds']);
$check('the pass created people (else this run proves little: use --prepare)', true, $stats['newClusters'] > 0);

$check('nothing written by load and assign', $before, $fingerprint());

// persistAssignments() is private: reach it the way a future caller could by mistake, with a temporary label.
$refused = 'no exception';
$probe = $reopened['detections'];
$someId = array_key_first($probe);
if ($someId !== null) {
	$probe[$someId]['cluster_id'] = -1000001;
	$method = new \ReflectionMethod(ClusteringService::class, 'persistAssignments');
	try {
		$method->invoke($clustering, $user, $probe, [$someId], []);
	} catch (\LogicException $e) {
		$refused = 'LogicException';
	}
}
$check('a temporary label is refused', 'LogicException', $refused);
$check('nothing written after the refusal', $before, $fingerprint());
