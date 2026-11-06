<?php
// Calls the readers behind sharing, reset and the commands directly, including the private ones, on a
// deterministic sample of files, and prints the results as JSON. Run it before and after a change and diff
// (see reads-direct.sh). Read-only: every call is a SELECT or a dry run.
//
// Environment: USERS (comma list, default "admin,bob"), SAMPLE (files per group, default 150).
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';

use OCA\FacewrightBridge\Command\{ClusterFaces, ReopenUnassigned, ResetFacewrightData};
use OCA\FacewrightBridge\Service\{AccessSyncService, ClusteringService, DetectionService, ResetService};
use OCP\Server;
use Symfony\Component\Console\Output\BufferedOutput;

$users = array_values(array_filter(explode(',', getenv('USERS') ?: 'admin,bob')));
$sample = (int)(getenv('SAMPLE') ?: 150);
$priv = function (object $o, string $m, array $args = []) { $r = new ReflectionMethod($o, $m); $r->setAccessible(true); return $r->invokeArgs($o, $args); };
$db = Server::get(\OCP\IDBConnection::class);
$faces = $db->getQueryBuilder()->getTableName('facewright_faces');
$ours = $db->getQueryBuilder()->getTableName('facewright_user_faces');

// A sample of files held by more than one user, and of files held by one: the multi-user case is the one that
// decides who donates rows and who gets pruned, and is the one a single-user fixture would never reach.
$shared = $db->executeQuery("SELECT f.file_id FROM {$faces} f JOIN {$ours} x ON x.face_id = f.id GROUP BY f.file_id HAVING COUNT(DISTINCT x.user_id) > 1 ORDER BY MD5(f.file_id) LIMIT {$sample}")->fetchAll(\PDO::FETCH_COLUMN);
$others = $db->executeQuery("SELECT f.file_id FROM {$faces} f JOIN {$ours} x ON x.face_id = f.id GROUP BY f.file_id HAVING COUNT(DISTINCT x.user_id) = 1 ORDER BY MD5(f.file_id) LIMIT {$sample}")->fetchAll(\PDO::FETCH_COLUMN);
$files = array_map('intval', array_merge($shared, $others, [999999991, 999999992])); // the last two: nobody has rows
sort($files);

$access = Server::get(AccessSyncService::class);
$det = Server::get(DetectionService::class);
$out = ['sample' => ['shared' => count($shared), 'others' => count($others), 'users' => $users], 'files' => []];
$unclustered = Server::get(ClusteringService::class)->countUnclusteredByUser(); ksort($unclustered);
$out['unclusteredByUser'] = $unclustered;
foreach ($files as $f) {
	$u = $access->usersWithRowsFor($f); sort($u);
	$d = $priv($det, 'usersWithRowsFor', [$f, array_merge($users, ['nobody'])]); sort($d);
	$rec = $access->reconcileFile($f, true, true);
	ksort($rec['dryRunCopy']); ksort($rec['dryRunPrune']);
	$out['files'][$f] = [
		'usersWithRowsFor' => $u,
		'detection_usersWithRowsFor' => $d,
		'findDonor' => $access->findDonor($f, array_merge($users, ['nobody'])),
		'findDonor_last_user_only' => $access->findDonor($f, [end($users)]),
		'countOwnRowsForUsers' => $priv($access, 'countOwnRowsForUsers', [$f, $users]),
		'reconcileFile_dry' => $rec,
		'copyExisting_dry' => $access->copyExisting($f, [end($users)], true),
	];
}
[$ids, $clusters, $faceIds] = $priv(Server::get(ResetService::class), 'findOwnRows');
sort($ids); sort($clusters); sort($faceIds);
$out['reset'] = ['detections' => count($ids), 'detections_md5' => md5(implode(',', $ids)), 'clusters' => count($clusters), 'clusters_md5' => md5(implode(',', $clusters)), 'faces' => count($faceIds), 'faces_md5' => md5(implode(',', $faceIds))];
$out['resetCommand_countOwnRows'] = $priv(Server::get(ResetFacewrightData::class), 'countOwnRows');
foreach ($users as $user) {
	$o = new BufferedOutput(); $priv(Server::get(ClusterFaces::class), 'printClusterSizes', [$o, $user]);
	$lines = array_filter(explode("\n", $o->fetch())); sort($lines); $out['clusterSizes'][$user] = array_values($lines);
}
$re = Server::get(ReopenUnassigned::class);
$out['reopen_countMatching'] = ['all' => $priv($re, 'countMatching', [null])];
foreach ($users as $user) { $out['reopen_countMatching'][$user] = $priv($re, 'countMatching', [$user]); }
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
