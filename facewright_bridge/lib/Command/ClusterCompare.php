<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\ClusteringCompareService;
use OCA\FacewrightBridge\Service\ExAppClusteringException;
use OCA\FacewrightBridge\Service\UserSettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs PHP clustering and the ExApp's /cluster side by side on one user's pool and reports where they differ
 * (docs/exapp-clustering.md, C4). Writes nothing to Nextcloud's database; fills the ExApp's embedding cache.
 * Exit 0: no unexplained difference. 1: at least one. 2: nothing to attempt.
 */
class ClusterCompare extends Command {
	public function __construct(
		private ClusteringCompareService $compare,
		private UserSettingsService $userSettings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:cluster-compare')
			->setDescription('Compare PHP clustering with the ExApp\'s /cluster on a user\'s pool, writing nothing')
			->addArgument('user', InputArgument::REQUIRED)
			->addOption('reopen', null, InputOption::VALUE_NONE, 'Treat unclusterable faces as pending, as a threshold change would (in memory only)')
			->addOption('chunks', null, InputOption::VALUE_REQUIRED, 'Stop after this many chunks (0: all)', '0')
			->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Faces attempted per chunk, as ClusterScanJob', '300')
			->addOption('max-distance', null, InputOption::VALUE_REQUIRED, 'Default: the user\'s effective setting')
			->addOption('min-faces', null, InputOption::VALUE_REQUIRED, 'Default: the user\'s effective setting')
			->addOption('show', null, InputOption::VALUE_REQUIRED, 'Differences to print in detail', '20');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');
		$maxDistance = $input->getOption('max-distance') !== null ? (float)$input->getOption('max-distance') : $this->userSettings->getClusterMaxDistance($userId);
		$minFaces = $input->getOption('min-faces') !== null ? (int)$input->getOption('min-faces') : $this->userSettings->getClusterMinFaces($userId);
		$output->writeln("Comparing for {$userId}: max distance {$maxDistance}, min faces {$minFaces}" . ($input->getOption('reopen') ? ', reopened' : ''));

		$printChunk = function (array $r) use ($output): void {
			$d = $r['differences'];
			$output->writeln(sprintf(
				'chunk %d: %d attempted | PHP %d new %d join %d skip, %d ms | ExApp %d new %d join %d skip, %d ms (compute %d ms, %d request(s), %.1f MB) | differences: %d boundary, %d tie, %d unexplained',
				$r['chunk'], $r['attempted'],
				$r['php']['new'], $r['php']['join'], $r['php']['skip'], $r['php']['ms'],
				$r['exapp']['new'], $r['exapp']['join'], $r['exapp']['skip'], $r['exapp']['ms'], $r['exapp']['compute_ms'], $r['exapp']['requests'], $r['exapp']['bytes'] / 1048576,
				$d['boundary'], $d['tie'], $d['unexplained'],
			));
		};

		try {
			$result = $this->compare->compare(
				$userId,
				(bool)$input->getOption('reopen'),
				max(0, (int)$input->getOption('chunks')),
				max(1, (int)$input->getOption('chunk-size')),
				$maxDistance,
				$minFaces,
				max(0, (int)$input->getOption('show')),
				$printChunk,
			);
		} catch (ExAppClusteringException $e) {
			$output->writeln('<error>ExApp: ' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln("Pool {$result['poolSize']} faces, {$result['attemptable']} to attempt.");
		if ($result['attemptable'] === 0) {
			$output->writeln('Nothing to attempt: no face is pending. Use --reopen to compare on the unclusterable faces.');
			return 2;
		}
		$s = $result['sync'];
		$output->writeln(sprintf('Cache sync: %d checked, %d uploaded, %d request(s), %.1f MB, %d ms.', $s['checked'], $s['uploaded'], $s['requests'], $s['bytes'] / 1048576, $s['ms']));
		$t = $result['totals'];
		$output->writeln(sprintf(
			'Totals over %d chunk(s): PHP %d new %d join %d skip, %.1f s | ExApp %d new %d join %d skip, %.1f s round trip (%.1f s compute, %d request(s), %.1f MB)',
			$result['chunks'],
			$t['php']['new'], $t['php']['join'], $t['php']['skip'], $t['php']['ms'] / 1000,
			$t['exapp']['new'], $t['exapp']['join'], $t['exapp']['skip'], $t['exapp']['ms'] / 1000, $t['exapp']['compute_ms'] / 1000, $t['exapp']['requests'], $t['exapp']['bytes'] / 1048576,
		));
		$output->writeln(sprintf('PHP peak memory: %.0f MB.', $result['peakMemory'] / 1048576));
		$d = $t['differences'];
		$output->writeln(sprintf('Differences: %d boundary, %d tie, %d unexplained.', $d['boundary'], $d['tie'], $d['unexplained']));
		foreach ($result['differences'] as $diff) {
			$output->writeln(sprintf('  %s row %s: PHP %s, ExApp %s', $diff['class'], $diff['id'] ?? '-', var_export($diff['php'], true), var_export($diff['exapp'], true)));
		}
		return $d['unexplained'] === 0 ? 0 : 1;
	}
}
