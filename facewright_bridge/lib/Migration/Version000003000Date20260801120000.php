<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds an index on facewright_processed.file_id for the multi-user fan-out.
 *
 * The table's only existing index is UNIQUE (user_id, file_id) - leading column user_id - which is
 * useless for a lookup by file_id alone. Every query fan-out introduces does exactly that:
 * ProcessedFileService::getProcessedUsers() (once per queued detection), the two prune deletes
 * (once per deleted file, on the *synchronous* path of the delete request), and
 * countProcessedFiles()'s COUNT(DISTINCT file_id) on every admin page load. Without this index all
 * of those are full scans of a table that already holds 54k rows and grows with the sharing factor.
 */
class Version000003000Date20260801120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('facewright_processed')) {
			return null;
		}

		$table = $schema->getTable('facewright_processed');
		if (!$table->hasIndex('facewright_proc_file')) {
			$table->addIndex(['file_id'], 'facewright_proc_file');
		}

		return $schema;
	}
}
