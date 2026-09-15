<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\FaceMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resumable backfill of facewright_faces / facewright_detection_faces for detections written
 * before Stage 1 of docs/independence-implementation.md - see FaceMigrationService for the actual
 * algorithm. Safe to interrupt and re-run: the cursor (appconfig
 * facewright_bridge/migrate_faces_after_file_id) survives, and re-processing a chunk that
 * partially completed skips rows already linked.
 */
class MigrateFaces extends Command {
	public function __construct(
		private FaceMigrationService $migration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:migrate-faces')
			->setDescription('Backfill facewright_faces/facewright_detection_faces for detections written before Stage 1')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be migrated without writing')
			->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Files per chunk', '500')
			->addOption('reset-cursor', null, InputOption::VALUE_NONE, 'Restart from file_id 0, ignoring any saved progress');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('reset-cursor')) {
			$this->migration->resetCursor();
			$output->writeln('Cursor reset - next run starts from file_id 0.');
		}

		$dryRun = $input->getOption('dry-run');
		$chunkSize = max(1, (int)$input->getOption('chunk'));

		$totalFiles = 0;
		$totalFaces = 0;
		$totalLinks = 0;
		$afterFileId = $this->migration->getCursor();

		while (true) {
			$result = $this->migration->migrateChunk($afterFileId, $chunkSize, $dryRun);
			$totalFiles += $result['filesProcessed'];
			$totalFaces += $result['facesCreated'];
			$totalLinks += $result['linksCreated'];

			foreach ($result['skipped'] as $skip) {
				fwrite(STDERR, sprintf(
					"Skipped degenerate group in file %d: detection id(s) %s (embedding doesn't decode to %d floats)\n",
					$skip['fileId'],
					implode(', ', $skip['ids']),
					\OCA\FacewrightBridge\Service\FaceVectorCodec::DIMENSIONS,
				));
			}

			$output->writeln(sprintf(
				'chunk: %d file(s), %d face(s), %d link(s)%s',
				$result['filesProcessed'],
				$result['facesCreated'],
				$result['linksCreated'],
				$dryRun ? ' (dry run)' : '',
			));

			if ($result['lastFileId'] === null) {
				break;
			}
			$afterFileId = $result['lastFileId'];
		}

		$output->writeln(sprintf(
			'Total: %d file(s), %d face(s) created, %d link(s) created%s.',
			$totalFiles,
			$totalFaces,
			$totalLinks,
			$dryRun ? ' (dry run - nothing written)' : '',
		));

		$check = $this->migration->verifyCompletion();
		$output->writeln(sprintf(
			'Reconciliation: %d own detection(s) long enough to be ours, %d linked, %d degenerate (skipped for good), %d unresolved.',
			$check['total'],
			$check['linked'],
			count($check['degenerate']),
			count($check['unresolved']),
		));
		if ($check['unresolved'] !== []) {
			$output->writeln('<error>Reconciliation failed - do not proceed to Stage 1 step 1.7 until this is 0.</error>');
			$output->writeln('Unresolved detection id(s): ' . implode(', ', $check['unresolved']));
			return 1;
		}

		// Fully caught up: nothing left above the cursor, and reconciliation confirms no legacy
		// row is unaccounted for. Delete the cursor rather than leave it pointing at the last
		// file_id seen - a later re-run (there's no harm in one) then starts its scan from 0
		// instead of a stale position that only happened to be "the end" at this point in time.
		if (!$dryRun) {
			$this->migration->resetCursor();
		}

		return 0;
	}
}
