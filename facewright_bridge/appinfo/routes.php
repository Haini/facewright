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

		// A face as a square crop, for <img src> on the People page - see FacePreviewController.
		['name' => 'FacePreview#preview', 'url' => '/faces/{id}/preview', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		// Personal settings. Separate controller, every action #[NoAdminRequired], every action
		// scoped to the session user - see PersonalSettingsController.
		['name' => 'PersonalSettings#setEnabled', 'url' => '/personal/enabled', 'verb' => 'PUT'],
		['name' => 'PersonalSettings#setIncludedPaths', 'url' => '/personal/included-paths', 'verb' => 'PUT'],
		['name' => 'PersonalSettings#runScan', 'url' => '/personal/run-scan', 'verb' => 'PUT'],
		['name' => 'PersonalSettings#runClustering', 'url' => '/personal/run-clustering', 'verb' => 'PUT'],
	],

	// Session-scoped OCS API over PeopleService - see PeopleApiController. Resulting URLs are
	// under /ocs/v2.php/apps/facewright_bridge/api/v1/... The static paths ("unassigned",
	// "ignored", "merge", "delete") are declared before the {id} routes so the {id} pattern can't
	// swallow them; the {id} routes also require digits.
	'ocs' => [
		['name' => 'PeopleApi#listClusters', 'url' => '/api/v1/people', 'verb' => 'GET'],
		['name' => 'PeopleApi#createCluster', 'url' => '/api/v1/people', 'verb' => 'POST'],
		['name' => 'PeopleApi#listUnassignedFaces', 'url' => '/api/v1/people/unassigned', 'verb' => 'GET'],
		['name' => 'PeopleApi#listIgnoredFaces', 'url' => '/api/v1/people/ignored', 'verb' => 'GET'],
		['name' => 'PeopleApi#mergeMany', 'url' => '/api/v1/people/merge', 'verb' => 'POST'],
		['name' => 'PeopleApi#deleteMany', 'url' => '/api/v1/people/delete', 'verb' => 'POST'],
		['name' => 'PeopleApi#listFaces', 'url' => '/api/v1/people/{id}/faces', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'PeopleApi#renameCluster', 'url' => '/api/v1/people/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'PeopleApi#deleteCluster', 'url' => '/api/v1/people/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'PeopleApi#mergeClusters', 'url' => '/api/v1/people/{id}/merge', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'PeopleApi#moveFaces', 'url' => '/api/v1/faces/move', 'verb' => 'POST'],
		['name' => 'PeopleApi#detachFaces', 'url' => '/api/v1/faces/detach', 'verb' => 'POST'],
		['name' => 'PeopleApi#ignoreFaces', 'url' => '/api/v1/faces/ignore', 'verb' => 'POST'],
		['name' => 'PeopleApi#unignoreFaces', 'url' => '/api/v1/faces/unignore', 'verb' => 'POST'],
		['name' => 'PeopleApi#moveFace', 'url' => '/api/v1/faces/{id}/cluster', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'PeopleApi#detachFace', 'url' => '/api/v1/faces/{id}/cluster', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'PeopleApi#facesForFile', 'url' => '/api/v1/files/{fileId}/faces', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],
	],
];
