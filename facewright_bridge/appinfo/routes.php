<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'PeopleSettings#setEnabled', 'url' => '/people-enabled', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setAutomaticDetectionEnabled', 'url' => '/automatic-detection-enabled', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setAutomaticClusteringEnabled', 'url' => '/automatic-clustering-enabled', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setProcessingPaused', 'url' => '/processing-paused', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#runBatch', 'url' => '/run-batch', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#runScan', 'url' => '/run-scan', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#runClustering', 'url' => '/run-clustering', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#mergeClusters', 'url' => '/merge-clusters', 'verb' => 'PUT'],

		// Personal settings. Separate controller, every action #[NoAdminRequired], every action
		// scoped to the session user - see PersonalSettingsController.
		['name' => 'PersonalSettings#setEnabled', 'url' => '/personal/enabled', 'verb' => 'PUT'],
		['name' => 'PersonalSettings#setIncludedPaths', 'url' => '/personal/included-paths', 'verb' => 'PUT'],
		['name' => 'PersonalSettings#runScan', 'url' => '/personal/run-scan', 'verb' => 'PUT'],
		['name' => 'PersonalSettings#runClustering', 'url' => '/personal/run-clustering', 'verb' => 'PUT'],
	],
];
