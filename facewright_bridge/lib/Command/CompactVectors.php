<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\VectorCompactionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rewrites embeddings written before DetectionService::VECTOR_PRECISION existed. See
 * VectorCompactionService for why this is a command rather than a migration step.
 *
 * Note the space is not returned to the filesystem by this alone - InnoDB frees the pages into the
 * tablespace and reuses them, so the file only shrinks after an OPTIMIZE TABLE. That is a locking
 * full rebuild and deliberately not done here.
 */
class CompactVectors extends Command {
	public function __construct(
		private VectorCompactionService $compaction,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:compact-vectors')
			->setDescription('Re-encode stored face embeddings at reduced precision, shrinking them ~2.2x without measurable effect on clustering')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything')
			->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Rows per database round', '500')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many rows - the safe way to make a first live run small', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$chunkSize = max(1, (int)$input->getOption('chunk-size'));
		$limit = max(0, (int)$input->getOption('limit'));

		$remaining = $this->compaction->countRemaining();
		$output->writeln(sprintf('%s row(s) still in the old encoding.', number_format($remaining)));
		if ($remaining === 0) {
			$output->writeln('Nothing to do.');
			return 0;
		}

		$totals = ['examined' => 0, 'compacted' => 0, 'skipped' => 0, 'before' => 0, 'after' => 0];
		$afterId = 0;
		$started = microtime(true);

		while (true) {
			$size = $limit > 0 ? min($chunkSize, $limit - $totals['examined']) : $chunkSize;
			if ($size <= 0) {
				break;
			}

			$r = $this->compaction->compactChunk($afterId, $size, $dryRun);
			foreach (['examined', 'compacted', 'skipped', 'before', 'after'] as $k) {
				$totals[$k] += $r[$k];
			}
			if ($r['lastId'] === null) {
				break;
			}
			$afterId = $r['lastId'];

			// Progress on one rewritten line rather than a line per chunk - this runs for minutes.
			$output->write(sprintf(
				"\r  %s compacted, %s skipped, %.1f MB -> %.1f MB, %.0fs elapsed   ",
				number_format($totals['compacted']),
				number_format($totals['skipped']),
				$totals['before'] / 1048576,
				$totals['after'] / 1048576,
				microtime(true) - $started,
			));

			if ($r['examined'] < $size) {
				break;
			}
		}
		$output->writeln('');

		$saved = ($totals['before'] - $totals['after']) / 1048576;
		$output->writeln('');
		$output->writeln(sprintf('%s row(s) examined, %s compacted, %s skipped.',
			number_format($totals['examined']), number_format($totals['compacted']), number_format($totals['skipped'])));
		if ($totals['compacted'] > 0) {
			$output->writeln(sprintf('Vector bytes: %.1f MB -> %.1f MB (%.2fx, %.1f MB saved).',
				$totals['before'] / 1048576, $totals['after'] / 1048576,
				$totals['before'] / max(1, $totals['after']), $saved));
			// The headline number is the table, not the vectors: a compacted row drops back under
			// InnoDB's off-page limit, so it stops occupying a private 16 KB page as well.
			$output->writeln('Table shrinkage will exceed this - compacted rows fit back in-page, releasing their off-page pages too.');
		}
		if ($dryRun) {
			$output->writeln('<comment>Dry run - nothing was written.</comment>');
		} else {
			$output->writeln(sprintf('%s row(s) still to do. Space returns to the filesystem only after OPTIMIZE TABLE.',
				number_format($this->compaction->countRemaining())));
		}
		return 0;
	}
}
