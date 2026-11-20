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
 *   --target=T  only the target T (`recognize` or `own`), configured or not; the default is every configured target
 *               (app config `projection_targets`, unset = `recognize`), one report each. --hydrate is `recognize` only.
 *
 * Categories: A visible face missing or different; B hidden-state remnant in Recognize's table (tolerated until
 * Stage F, never touched; `recognize` only); C unknown stale copy of ours; D person missing, different or stale.
 * The exit code is 1 if any target reported something to repair, or still has something after the repair.
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
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Repair at most N rows per category in this run (0 = all)', '0')
			->addOption('target', null, InputOption::VALUE_REQUIRED, 'Only this target (recognize or own); default: every configured target');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$only = $input->getOption('target') !== null ? (string)$input->getOption('target') : null;
		if ($only !== null && !ProjectionService::isTarget($only)) {
			$output->writeln("<error>Unknown target '{$only}': use recognize or own.</error>");
			return Command::INVALID;
		}
		$user = $input->getOption('user') !== null ? (string)$input->getOption('user') : null;
		$dry = (bool)$input->getOption('dry-run');

		if ($input->getOption('hydrate')) {
			if ($only !== null && $only !== ProjectionService::TARGET_RECOGNIZE) {
				$output->writeln("<error>--hydrate is the rollback to Recognize's tables and takes no other target (got '{$only}').</error>");
				return Command::INVALID;
			}
			if (!$this->projection->availableFor(ProjectionService::TARGET_RECOGNIZE)) {
				$output->writeln('<error>The projection to Recognize\'s tables is not available: it needs MariaDB, our tables and Recognize\'s with the columns it writes.</error>');
				return 1;
			}
			$n = $this->projection->hydrate($user, $dry);
			$output->writeln(($dry ? 'Would write' : 'Wrote') . " {$n['faces']} face(s) and {$n['people']} person(s) into Recognize's tables, all states.");
			return 0;
		}

		$limit = max(0, (int)$input->getOption('limit'));
		$seq = $this->projection->dirtySeq();
		$targets = $only !== null ? [$only] : $this->projection->targets();
		$exit = 0;
		foreach ($targets as $target) {
			$output->writeln("Target: {$target}");
			if (!$this->projection->availableFor($target)) {
				$output->writeln("<error>The projection to {$this->label($target)} is not available: it needs MariaDB, our tables and the target's tables with the columns it writes.</error>");
				$exit = 1;
				continue;
			}
			$report = $this->projection->reconcile($dry, $user, $limit, $target);
			$this->print($output, $report, $dry, $target);
			if ($dry) {
				if ($report['A'] + $report['C'] + $report['D_missing'] + $report['D_stale'] > 0) {
					$exit = 1;
				}
				continue;
			}

			$after = $this->projection->reconcile(true, $user, 0, $target);
			$left = $after['A'] + $after['C'] + $after['D_missing'] + $after['D_stale'];
			$output->writeln($left === 0 ? ucfirst($this->label($target)) . ' now match ours.' : "<error>{$left} difference(s) remain in {$this->label($target)} (A {$after['A']}, C {$after['C']}, D {$after['D_missing']} + {$after['D_stale']}).</error>");
			if ($left !== 0) {
				$exit = 1;
			}
		}
		// The flag is shared by every target: it is cleared only by a run that covered all of them, for everyone, and
		// left nothing anywhere.
		if (!$dry && $exit === 0 && $user === null && $only === null) {
			$this->projection->markClean($seq);
		}
		return $exit;
	}

	private function label(string $target): string {
		return ProjectionService::label($target);
	}

	/** @param array<string, mixed> $r */
	private function print(OutputInterface $output, array $r, bool $dry, string $target): void {
		$verb = $dry ? 'would repair' : 'repaired';
		$fix = fn (string $k) => $dry ? '' : " ({$verb} {$r['repaired'][$k]})";
		if ($target === ProjectionService::TARGET_RECOGNIZE) {
			$output->writeln("A  visible faces missing or different in Recognize's table: {$r['A']}" . $fix('A'));
			$output->writeln("B  hidden-state rows still in Recognize's table (tolerated until Stage F): {$r['B']}");
			$output->writeln("C  stale copies of ours with no row of ours: {$r['C']}" . $fix('C'));
			$output->writeln("   Recognize's own legacy rows (never touched): {$r['legacy']}");
		} else {
			$output->writeln("A  visible faces missing or different in the {$target} detections: {$r['A']}" . $fix('A'));
			$output->writeln("C  rows there that are not a visible face of ours: {$r['C']}" . $fix('C'));
		}
		$output->writeln("D  people missing or different there: {$r['D_missing']}" . $fix('D_missing'));
		$output->writeln("D  people there that nothing references: {$r['D_stale']}" . $fix('D_stale'));
		if ($r['truncated']) {
			$output->writeln('<comment>--limit reached: run again to continue.</comment>');
		}
	}
}
