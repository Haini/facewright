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

	// Session-scoped OCS API over PeopleService - see PeopleApiController. Resulting URLs are
	// under /ocs/v2.php/apps/facewright_bridge/api/v1/... The "unassigned" route is declared
	// before the {id} routes so it isn't swallowed by the {id} pattern.
	'ocs' => [
		['name' => 'PeopleApi#listClusters', 'url' => '/api/v1/people', 'verb' => 'GET'],
		['name' => 'PeopleApi#createCluster', 'url' => '/api/v1/people', 'verb' => 'POST'],
		['name' => 'PeopleApi#listUnassignedFaces', 'url' => '/api/v1/people/unassigned', 'verb' => 'GET'],
		['name' => 'PeopleApi#listFaces', 'url' => '/api/v1/people/{id}/faces', 'verb' => 'GET'],
		['name' => 'PeopleApi#renameCluster', 'url' => '/api/v1/people/{id}', 'verb' => 'PUT'],
		['name' => 'PeopleApi#deleteCluster', 'url' => '/api/v1/people/{id}', 'verb' => 'DELETE'],
		['name' => 'PeopleApi#mergeClusters', 'url' => '/api/v1/people/{id}/merge', 'verb' => 'POST'],
		['name' => 'PeopleApi#moveFace', 'url' => '/api/v1/faces/{id}/cluster', 'verb' => 'PUT'],
		['name' => 'PeopleApi#detachFace', 'url' => '/api/v1/faces/{id}/cluster', 'verb' => 'DELETE'],
		['name' => 'PeopleApi#facesForFile', 'url' => '/api/v1/files/{fileId}/faces', 'verb' => 'GET'],
	],
];
