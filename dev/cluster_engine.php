<?php
// Helper for dev/cluster-engine.sh (step S3 of docs/exapp-clustering.md). Runs inside the Nextcloud container.
//   php cluster_engine.php reset USER          ClusteringService::resetUser(): every face of USER pending again
//   php cluster_engine.php mem USER php|exapp  reset, then one PHP chunk (300) or one ExApp pass; prints
//                                              "mem <engine> <baseline MB> <peak MB> <pool> <attempted> <written>"
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';

use OCA\FacewrightBridge\Service\ClusteringService;

[, $mode, $user] = $argv;
$clustering = \OCP\Server::get(ClusteringService::class);
$clustering->resetUser($user);
if ($mode === 'reset') {
	echo "reset {$user}\n";
	exit(0);
}

$engine = $argv[3];
$pool = count($clustering->loadPoolIds($user)['rows']);   // before the pass: its writes cap people at 20 representatives
gc_collect_cycles();
memory_reset_peak_usage();
$baseline = memory_get_usage();
$r = $engine === 'exapp'
	? $clustering->clusterUserExApp($user, 0.5, 3)
	: $clustering->clusterUserChunk($user, 0.5, 3, 0, 300);
$peak = memory_get_peak_usage();
printf("mem %s %.1f %.1f %d %d %d\n", $engine, $baseline / 1048576, $peak / 1048576, $pool, $r['attempted'], $r['written']);
