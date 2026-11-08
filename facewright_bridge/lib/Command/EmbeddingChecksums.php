<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fills facewright_faces.embedding_crc for rows that lack it (docs/exapp-clustering.md, S1). Resumable by
 * construction: it only ever selects rows where the checksum is NULL, walking them by id. Rows written since the
 * column exists already have it (DetectionService::upsertFace()).
 */
class EmbeddingChecksums extends Command {
	public function __construct(
		private IDBConnection $db,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:embedding-checksums')
			->setDescription('Fill the stored CRC32 of face embeddings where it is missing (resumable)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the rows that lack it')
			->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Rows per statement', '5000');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$missing = $this->countMissing();
		$output->writeln("{$missing} face(s) without a stored checksum.");
		if ($input->getOption('dry-run') || $missing === 0) {
			return 0;
		}
		$chunk = max(1, (int)$input->getOption('chunk'));
		$inDatabase = in_array($this->db->getDatabaseProvider(true), [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB], true);

		$done = 0;
		$afterId = 0;
		while (true) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('facewright_faces')
				->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->isNull('embedding_crc'))
				->orderBy('id', 'ASC')
				->setMaxResults($chunk);
			$ids = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
			if ($ids === []) {
				break;
			}
			$done += $inDatabase ? $this->fillInDatabase($ids) : $this->fillInPhp($ids);
			$afterId = max($ids);
			$output->writeln("  {$done} done (up to id {$afterId})");
		}
		$output->writeln("Filled {$done} checksum(s); " . $this->countMissing() . ' still missing.');
		return 0;
	}

	/** @param list<int> $ids */
	private function fillInDatabase(array $ids): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('facewright_faces')
			->set('embedding_crc', $qb->createFunction('CRC32(' . $qb->getColumnName('embedding') . ')'))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			// A row a writer filled meanwhile keeps its own value.
			->andWhere($qb->expr()->isNull('embedding_crc'));
		return $qb->executeStatement();
	}

	/** @param list<int> $ids */
	private function fillInPhp(array $ids): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'embedding')
			->from('facewright_faces')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('embedding_crc'));
		$result = $qb->executeQuery();
		$done = 0;
		while ($row = $result->fetch()) {
			$blob = is_resource($row['embedding']) ? stream_get_contents($row['embedding']) : (string)$row['embedding'];
			$update = $this->db->getQueryBuilder();
			$update->update('facewright_faces')
				->set('embedding_crc', $update->createNamedParameter(crc32($blob), IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($update->expr()->isNull('embedding_crc'));
			$done += $update->executeStatement();
		}
		$result->closeCursor();
		return $done;
	}

	private function countMissing(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('facewright_faces')
			->where($qb->expr()->isNull('embedding_crc'));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
