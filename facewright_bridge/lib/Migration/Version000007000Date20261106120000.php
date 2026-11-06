<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The two tables that will hold people and face assignments, replacing the copies kept in
 * Recognize's tables (docs/own-tables.md, Stage C).
 *
 * facewright_clusters: one row per person. facewright_user_faces: one row per (user, physical face),
 * pointing at facewright_faces, with an explicit state instead of the sentinel values the Recognize
 * table overloads onto cluster_id (see FaceState).
 *
 * Both reuse the ids of the Recognize rows they copy (recognize_face_detections.id and
 * recognize_face_clusters.id), so everything that references those ids - cached centroids, merge
 * dismissals, Memories' cover table, the People page URLs - stays valid without a mapping table. The
 * ids are therefore written explicitly for now; the autoincrement only matters once the copies stop.
 *
 * No foreign keys, like every other table of ours. Filling the tables is a resumable occ command
 * (facewright:own-tables --migrate), not part of this migration: it can mean hundreds of thousands
 * of rows.
 */
class Version000007000Date20261106120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('facewright_clusters')) {
			$table = $schema->createTable('facewright_clusters');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
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
			$table->setPrimaryKey(['id'], 'fw_clusters_pk');
			$table->addIndex(['user_id'], 'fw_clusters_user');
		}

		if (!$schema->hasTable('facewright_user_faces')) {
			$table = $schema->createTable('facewright_user_faces');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('face_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('cluster_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('state', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id'], 'fw_ufaces_pk');
			$table->addUniqueIndex(['user_id', 'face_id'], 'fw_ufaces_user_face');
			$table->addIndex(['user_id', 'cluster_id'], 'fw_ufaces_user_cluster');
			$table->addIndex(['user_id', 'state'], 'fw_ufaces_user_state');
			$table->addIndex(['face_id'], 'fw_ufaces_face');
		}

		return $schema;
	}
}
