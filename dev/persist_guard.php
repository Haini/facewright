<?php
// Calls ClusteringService::persistAssignments() as a clustering pass would, after a human has changed rows behind
// its back, and prints what ended up written. Used by persist-guard.sh, which checks it. Changes data.
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';

use OCA\FacewrightBridge\Service\{ClusteringService, FaceState, FaceStore};
use OCP\Server;

$user = getenv('GUARD_USER');
$other = getenv('GUARD_OTHER');
$ids = array_map('intval', explode(',', getenv('GUARD_IDS'))); // pending, unclusterable, unassigned, ignored, moved, gone, foreign
[$pending, $unclusterable, $unassigned, $ignored, $moved, $gone, $foreign] = $ids;
$target = (int)getenv('GUARD_TARGET');
$humanPerson = (int)getenv('GUARD_HUMAN_PERSON');
$deadPerson = (int)getenv('GUARD_DEAD_PERSON');

$store = Server::get(FaceStore::class);
$created = $store->allocateCluster($user);   // a person this pass made, which ends up with no face

$detections = [];
foreach ([$pending, $unclusterable, $unassigned, $ignored, $moved, $foreign] as $id) {
	$detections[$id] = ['cluster_id' => $target];
}
$detections[$gone] = ['cluster_id' => $deadPerson];

$service = Server::get(ClusteringService::class);
$r = new ReflectionMethod($service, 'persistAssignments');
$r->setAccessible(true);
$written = $r->invokeArgs($service, [$user, $detections, array_keys($detections), [$created]]);
sort($written);
echo json_encode(['written' => array_map('intval', $written), 'created' => $created]), "\n";
