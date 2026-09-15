<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Stage 1 of docs/independence-implementation.md: two tables we own, so "is this row ours" can
 * become a join instead of a string-length proxy on Recognize's face_vector, and a per-file
 * physical face gets a stable identity independent of how many users' rows point at it.
 *
 * recognize_face_detections keeps carrying geometry, cluster_id and (until Stage 2) the JSON
 * face_vector - Recognize's own DAV code still decodes that column. Nothing is dropped here; it
 * is fully reversible until Stage 2's shim migration drops face_vector for good.
 *
 * facewright_faces: one row per physical face detected in a file - not per user. embedding is
 * 512 x float32 little-endian (FaceVectorCodec::BYTES = 2048), independent of how many users end
 * up with a recognize_face_detections row pointing at it via fan-out.
 *
 * facewright_detection_faces: links a per-user recognize_face_detections row to its physical
 * face. user_id is denormalized from the detection row purely so AccessSyncService's per-user
 * cleanup queries don't need a join back to recognize_face_detections to find "this user's
 * links".
 *
 * No foreign keys, matching every other migration in this app - Nextcloud's own migrations avoid
 * them; consistency is by code plus `occ facewright:migrate-faces`'s reconcile check.
 */
class Version000005000Date20260915000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('facewright_faces')) {
			$table = $schema->createTable('facewright_faces');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('face_index', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('x', Types::FLOAT, ['notnull' => true]);
			$table->addColumn('y', Types::FLOAT, ['notnull' => true]);
			$table->addColumn('width', Types::FLOAT, ['notnull' => true]);
			$table->addColumn('height', Types::FLOAT, ['notnull' => true]);
			$table->addColumn('det_score', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('embedding', Types::BLOB, ['notnull' => true]);
			$table->addColumn('model', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'buffalo_l',
			]);
			$table->setPrimaryKey(['id'], 'facewright_faces_id');
			$table->addUniqueIndex(['file_id', 'face_index'], 'facewright_faces_file_idx');
		}

		if (!$schema->hasTable('facewright_detection_faces')) {
			$table = $schema->createTable('facewright_detection_faces');
			$table->addColumn('detection_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('face_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->setPrimaryKey(['detection_id'], 'facewright_detfaces_det');
			$table->addIndex(['face_id'], 'facewright_detfaces_face');
			$table->addIndex(['user_id'], 'facewright_detfaces_user');
		}

		return $schema;
	}
}
