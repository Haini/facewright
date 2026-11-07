<?php
// The direct calls for own-traps.sh: a share copy repeated after a crash lost the marker, and a bad setState().
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';

use OCA\FacewrightBridge\Service\{AccessSyncService, FaceState, FaceStore};
use OCP\Server;

$u1 = getenv('U1'); $u2 = getenv('U2');
$db = Server::get(\OCP\IDBConnection::class);
$file = $db->executeQuery(
	'SELECT f.file_id FROM oc_facewright_faces f JOIN oc_facewright_user_faces a ON a.face_id = f.id AND a.user_id = ? '
	. 'WHERE NOT EXISTS (SELECT 1 FROM oc_facewright_faces g JOIN oc_facewright_user_faces b ON b.face_id = g.id AND b.user_id = ? WHERE g.file_id = f.file_id) ORDER BY f.file_id LIMIT 1',
	[$u1, $u2]
)->fetchOne();
$sync = Server::get(AccessSyncService::class);
$out = ['file' => (int)$file];
$out['first'] = $sync->copyToUsers((int)$file, $u1, [$u2]);
// The crash window: rows written, marker not.
$db->executeStatement('DELETE FROM oc_facewright_processed WHERE file_id = ? AND user_id = ?', [(int)$file, $u2]);
try {
	$out['second'] = $sync->copyToUsers((int)$file, $u1, [$u2]);
} catch (\Throwable $e) {
	$out['second'] = 'threw: ' . substr($e->getMessage(), 0, 60);
}
$out['marked'] = (int)$db->executeQuery('SELECT COUNT(*) FROM oc_facewright_processed WHERE file_id = ? AND user_id = ?', [(int)$file, $u2])->fetchOne();
$out['dupes'] = (int)$db->executeQuery('SELECT COUNT(*) FROM (SELECT user_id, face_id FROM oc_facewright_user_faces GROUP BY user_id, face_id HAVING COUNT(*) > 1) x')->fetchOne();
$face = (int)$db->executeQuery('SELECT id FROM oc_facewright_user_faces WHERE user_id = ? AND state = 1 ORDER BY id LIMIT 1', [$u1])->fetchOne();
$person = (int)$db->executeQuery('SELECT cluster_id FROM oc_facewright_user_faces WHERE id = ?', [$face])->fetchOne();
$db->beginTransaction();
try {
	Server::get(FaceStore::class)->setState($u1, [$face], FaceState::UNASSIGNED, $person);
	$out['refused'] = 0;
} catch (\InvalidArgumentException) {
	$out['refused'] = 1;
}
$db->rollBack();
echo json_encode($out), "\n";
