<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'PeopleSettings#setEnabled', 'url' => '/people-enabled', 'verb' => 'PUT'],
		['name' => 'PeopleSettings#setIncludedPaths', 'url' => '/included-paths', 'verb' => 'PUT'],
	],
];
