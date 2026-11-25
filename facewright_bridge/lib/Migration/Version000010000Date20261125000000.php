<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Migration;

use Closure;
use OCA\FacewrightBridge\Service\FaceStore;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Each person's size and cover, stored (docs/people-ui-review.md, items 5 and 6 follow-up). The People list sorted every
 * page by a GROUP BY over all of a user's assigned faces (100-200 ms on live, 100k faces) and sorted every face of the
 * largest people for their covers (130-200 ms for the first page). FaceStore keeps this table in step with every write;
 * PeopleService reads it.
 *
 * Its own table, not columns of facewright_clusters: a write into a person holds a shared lock on that person's row,
 * and two of them updating a column there would deadlock upgrading it.
 *
 * Filled here, from our tables: one row per person, a few thousand rows computed in seconds, unlike the copies of
 * Version000009. `occ facewright:own-tables --recount` rebuilds it the same way.
 */
class Version000010000Date20261125000000 extends SimpleMigrationStep {
	public function __construct(
		private FaceStore $faceStore,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('facewright_cluster_stats')) {
			$table = $schema->createTable('facewright_cluster_stats');
			$table->addColumn('cluster_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('face_count', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			// facewright_user_faces.id of the person's best face; NULL when it has none.
			$table->addColumn('cover_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->setPrimaryKey(['cluster_id'], 'fw_cstats_pk');
			$table->addIndex(['user_id'], 'fw_cstats_user');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info('facewright_bridge: stored the size and cover of ' . $this->faceStore->rebuildPeopleStats() . ' people');
	}
}
