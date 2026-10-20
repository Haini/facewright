<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Merge suggestions for the People page (step 6 of docs/people-ui-implementation.md).
 *
 * facewright_cluster_centroids: a cache of one averaged, L2-normalized embedding per cluster
 * (512 x float32 little-endian, FaceVectorCodec), so suggesting merges compares ~2 KB per person
 * instead of reading ten embeddings per candidate on every request. member_count is the cluster's
 * size when the centroid was computed; a differing current size marks it stale.
 *
 * facewright_merge_dismissals: pairs of people the user said are not the same person, so a
 * dismissed suggestion doesn't come back. cluster_a < cluster_b always (the code normalizes), so
 * the unique index treats (a, b) and (b, a) as one pair.
 *
 * Both reference recognize_face_clusters ids, which are autoincrement and never reused, so a row
 * left behind by a person deleted from Memories' side is inert rather than wrong. No foreign
 * keys, like every other table of ours.
 */
class Version000006000Date20261020000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('facewright_cluster_centroids')) {
			$table = $schema->createTable('facewright_cluster_centroids');
			$table->addColumn('cluster_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('centroid', Types::BLOB, ['notnull' => true]);
			$table->addColumn('member_count', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('computed_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->setPrimaryKey(['cluster_id'], 'fw_centroid_pk');
			$table->addIndex(['user_id'], 'fw_centroid_user');
		}

		if (!$schema->hasTable('facewright_merge_dismissals')) {
			$table = $schema->createTable('facewright_merge_dismissals');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('cluster_a', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('cluster_b', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->setPrimaryKey(['id'], 'fw_dismiss_pk');
			$table->addUniqueIndex(['user_id', 'cluster_a', 'cluster_b'], 'fw_dismiss_pair');
		}

		return $schema;
	}
}
