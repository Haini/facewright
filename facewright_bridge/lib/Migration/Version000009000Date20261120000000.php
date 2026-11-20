<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The `own` projection target: a copy of our people data in Recognize's format, in tables nothing else writes
 * (docs/memories-fork.md, M5). The fork of Memories reads `{provider}_face_detections` / `{provider}_face_clusters`;
 * with the provider set to this app's id it reads these two.
 *
 * They hold only the columns Memories reads (`RecognizeBackend`: id, user_id, file_id, the four box fractions,
 * cluster_id; and for people id, user_id, title) and Recognize's indexes, so Memories' query plans stay the ones it has
 * today. There is no autoincrement: every row is written with the id it has in facewright_user_faces /
 * facewright_clusters (ProjectionService), which is also what keeps Memories' cover table valid.
 *
 * No data here. The copy is made by `occ facewright:project --target=own` (resumable, and the hourly job repairs it);
 * a migration is the wrong place for hundreds of thousands of rows. With the prefix both table names stay under
 * Nextcloud's limit of 63 characters; index names are kept short for the same reason.
 */
class Version000009000Date20261120000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('facewright_bridge_face_detections')) {
			$table = $schema->createTable('facewright_bridge_face_detections');
			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			// Fractions of the image, top-left and size, like Recognize's columns (a double on MariaDB).
			foreach (['x', 'y', 'width', 'height'] as $column) {
				$table->addColumn($column, Types::FLOAT, [
					'notnull' => true,
				]);
			}
			// The person's id, or -1 for unassigned: the sparse copy holds nothing else.
			$table->addColumn('cluster_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->setPrimaryKey(['id'], 'fwb_fdet_pk');
			$table->addIndex(['cluster_id'], 'fwb_fdet_cluster');
			$table->addIndex(['user_id'], 'fwb_fdet_user');
			$table->addIndex(['file_id'], 'fwb_fdet_file');
		}

		if (!$schema->hasTable('facewright_bridge_face_clusters')) {
			$table = $schema->createTable('facewright_bridge_face_clusters');
			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 4000,
				'default' => '',
			]);
			$table->setPrimaryKey(['id'], 'fwb_fclust_pk');
			$table->addIndex(['user_id'], 'fwb_fclust_user');
		}

		return $schema;
	}
}
