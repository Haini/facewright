<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'PeopleSettings#setEnabled', 'url' => '/people-enabled', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setIncludedPaths', 'url' => '/included-paths', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setAutomaticDetectionEnabled', 'url' => '/automatic-detection-enabled', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#runBatch', 'url' => '/run-batch', 'verb' => 'PUT'],
	],
];
