<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\ProjectionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compares the copy Memories reads (`facewright_bridge_face_detections` / `_clusters`) with our tables and repairs the
 * difference: ours always wins (docs/own-tables.md, Stage E).
 *
 *   (no flag)   report, then repair
 *   --dry-run   report only, write nothing. Exit 1 if anything in A, C or D would need repair.
 *   --user=UID  restrict to one user
 *   --limit=N   repair at most N rows per category in this run (0 = all)
 *
 * Categories: A visible face missing or different; C row that is not a visible face of ours; D person missing,
 * different or stale. The exit code is 1 if anything needed repair (dry run), or is left after the repair.
 */
class Project extends Command {
	public function __construct(
		private ProjectionService $projection,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:project')
			->setDescription('Make the copy Memories reads match our tables; report and repair drift')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only; exit 1 if A, C or D need repair')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Restrict to one user')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Repair at most N rows per category in this run (0 = all)', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getOption('user') !== null ? (string)$input->getOption('user') : null;
		$dry = (bool)$input->getOption('dry-run');
		$limit = max(0, (int)$input->getOption('limit'));
		$seq = $this->projection->dirtySeq();

		$output->writeln('Copy: facewright_bridge_face_detections / facewright_bridge_face_clusters');
		if (!$this->projection->available()) {
			$output->writeln('<error>The projection is not available: it needs MariaDB, our tables and the copy\'s tables with the columns it writes.</error>');
			return 1;
		}
		$report = $this->projection->reconcile($dry, $user, $limit);
		$this->print($output, $report, $dry);
		if ($dry) {
			return $report['A'] + $report['C'] + $report['D_missing'] + $report['D_stale'] > 0 ? 1 : 0;
		}

		$after = $this->projection->reconcile(true, $user, 0);
		$left = $after['A'] + $after['C'] + $after['D_missing'] + $after['D_stale'];
		$output->writeln($left === 0 ? 'The copy now matches ours.' : "<error>{$left} difference(s) remain in the copy (A {$after['A']}, C {$after['C']}, D {$after['D_missing']} + {$after['D_stale']}).</error>");
		if ($left !== 0) {
			return 1;
		}
		// The dirty flag is cleared only by a run for everyone that left nothing behind.
		if ($user === null) {
			$this->projection->markClean($seq);
		}
		return 0;
	}

	/** @param array<string, mixed> $r */
	private function print(OutputInterface $output, array $r, bool $dry): void {
		$verb = $dry ? 'would repair' : 'repaired';
		$fix = fn (string $k) => $dry ? '' : " ({$verb} {$r['repaired'][$k]})";
		$output->writeln("A  visible faces missing or different in the copy: {$r['A']}" . $fix('A'));
		$output->writeln("C  rows there that are not a visible face of ours: {$r['C']}" . $fix('C'));
		$output->writeln("D  people missing or different there: {$r['D_missing']}" . $fix('D_missing'));
		$output->writeln("D  people there that no longer exist: {$r['D_stale']}" . $fix('D_stale'));
		if ($r['truncated']) {
			$output->writeln('<comment>--limit reached: run again to continue.</comment>');
		}
	}
}
