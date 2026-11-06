<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\ProjectionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compares Recognize's tables (what Memories reads) with ours and repairs the difference: ours always wins
 * (docs/own-tables.md, Stage E).
 *
 *   (no flag)   report, then repair
 *   --dry-run   report only, write nothing. Exit 1 if anything in A, C or D would need repair.
 *   --hydrate   rollback: write every face and person of ours into Recognize's tables with all five states as
 *               the sentinels Stage D code understands, delete nothing
 *   --user=UID  restrict to one user
 *   --limit=N   repair at most N rows per category in this run (0 = all)
 *
 * Categories: A visible face missing or different; B hidden-state remnant in Recognize's table (tolerated until
 * Stage F, never touched); C unknown stale copy of ours; D person missing, different or stale.
 */
class Project extends Command {
	public function __construct(
		private ProjectionService $projection,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:project')
			->setDescription('Make Recognize\'s tables match ours (what Memories shows); report and repair drift')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only; exit 1 if A, C or D need repair')
			->addOption('hydrate', null, InputOption::VALUE_NONE, 'Rollback: write all five states back into Recognize\'s tables')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Restrict to one user')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Repair at most N rows per category in this run (0 = all)', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->projection->available()) {
			$output->writeln('<error>The projection is not available: it needs MariaDB, our tables and Recognize\'s with the columns it writes.</error>');
			return 1;
		}
		$user = $input->getOption('user') !== null ? (string)$input->getOption('user') : null;
		$dry = (bool)$input->getOption('dry-run');

		if ($input->getOption('hydrate')) {
			$n = $this->projection->hydrate($user, $dry);
			$output->writeln(($dry ? 'Would write' : 'Wrote') . " {$n['faces']} face(s) and {$n['people']} person(s) into Recognize's tables, all states.");
			return 0;
		}

		$limit = max(0, (int)$input->getOption('limit'));
		$seq = $this->projection->dirtySeq();
		$report = $this->projection->reconcile($dry, $user, $limit);
		$this->print($output, $report, $dry);
		if ($dry) {
			return $report['A'] + $report['C'] + $report['D_missing'] + $report['D_stale'] > 0 ? 1 : 0;
		}

		$after = $this->projection->reconcile(true, $user, 0);
		$left = $after['A'] + $after['C'] + $after['D_missing'] + $after['D_stale'];
		$output->writeln($left === 0 ? 'Recognize\'s tables now match ours.' : "<error>{$left} difference(s) remain (A {$after['A']}, C {$after['C']}, D {$after['D_missing']} + {$after['D_stale']}).</error>");
		if ($left === 0 && $user === null) {
			$this->projection->markClean($seq);
		}
		return $left === 0 ? 0 : 1;
	}

	/** @param array<string, mixed> $r */
	private function print(OutputInterface $output, array $r, bool $dry): void {
		$verb = $dry ? 'would repair' : 'repaired';
		$fix = fn (string $k) => $dry ? '' : " ({$verb} {$r['repaired'][$k]})";
		$output->writeln("A  visible faces missing or different in Recognize's table: {$r['A']}" . $fix('A'));
		$output->writeln("B  hidden-state rows still in Recognize's table (tolerated until Stage F): {$r['B']}");
		$output->writeln("C  stale copies of ours with no row of ours: {$r['C']}" . $fix('C'));
		$output->writeln("   Recognize's own legacy rows (never touched): {$r['legacy']}");
		$output->writeln("D  people missing or different there: {$r['D_missing']}" . $fix('D_missing'));
		$output->writeln("D  people there that nothing references: {$r['D_stale']}" . $fix('D_stale'));
		if ($r['truncated']) {
			$output->writeln('<comment>--limit reached: run again to continue.</comment>');
		}
	}
}
