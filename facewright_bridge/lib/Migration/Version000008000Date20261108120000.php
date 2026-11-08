<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * facewright_faces.embedding_crc: the CRC32 of the embedding blob, which the ExApp's embedding cache is validated by
 * (docs/exapp-clustering.md, S1). Stored so a clustering pass need not read every blob to learn it.
 *
 * Nullable on purpose: NULL means "unknown", and readers then compute CRC32(embedding) as before, so a row a writer
 * left without it is slower, never wrong. Filling old rows is a resumable command (occ facewright:embedding-checksums),
 * not this migration. CRC32 is unsigned 32-bit, which fits a signed BIGINT.
 */
class Version000008000Date20261108120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('facewright_faces');
		if ($table->hasColumn('embedding_crc')) {
			return null;
		}
		$table->addColumn('embedding_crc', Types::BIGINT, ['notnull' => false]);
		return $schema;
	}
}
