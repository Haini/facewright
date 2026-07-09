<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tracks every file recognaize has attempted detection on, including zero-face results -
 * recognize_face_detections never gets a row for a zero-face photo, so it can't answer "has this
 * file been processed" on its own (that gap let zero-face files get reprocessed forever). Also
 * backs the admin-page stats (processed count, processed in the last hour).
 */
class Version000002000Date20260709120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('recognaize_processed')) {
			$table = $schema->createTable('recognaize_processed');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
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
			$table->addColumn('processed_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('faces_found', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id'], 'recognaize_proc_id');
			$table->addUniqueIndex(['user_id', 'file_id'], 'recognaize_proc_user_file');
			$table->addIndex(['processed_at'], 'recognaize_proc_time');
		}

		return $schema;
	}
}
