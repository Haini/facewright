<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'PeopleSettings#setEnabled', 'url' => '/people-enabled', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setIncludedPaths', 'url' => '/included-paths', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setAutomaticDetectionEnabled', 'url' => '/automatic-detection-enabled', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setProcessingPaused', 'url' => '/processing-paused', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#runBatch', 'url' => '/run-batch', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#runScan', 'url' => '/run-scan', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#runClustering', 'url' => '/run-clustering', 'verb' => 'PUT'],
	],
];
