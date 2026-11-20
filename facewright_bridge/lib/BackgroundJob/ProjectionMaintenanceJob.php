<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCA\FacewrightBridge\Service\ProjectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The safety net under the projection (docs/own-tables.md, Stage E): every writer pushes what it changed to
 * Recognize's tables right after it commits, and a push that fails only flags the copy as dirty. This job
 * compares the two sets of tables hourly and repairs whatever differs, which also covers what Recognize's own
 * handlers do to its tables behind our back (an access sync copying rows, a `.noimage` marker deleting them).
 *
 * Every configured target (`projection_targets`) is reconciled in turn, each in its own try. Capped per run; a larger
 * backlog continues on the next. The dirty flag is cleared only if the run ended with nothing left to repair in any
 * target and nothing newer had failed while it ran. Honours `processing_paused`, like every job here.
 */
class ProjectionMaintenanceJob extends TimedJob {
	/** Repairs per category per run. */
	private const LIMIT = 5000;

	public function __construct(
		ITimeFactory $time,
		private ProjectionService $projection,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			return;
		}
		$seq = $this->projection->dirtySeq();
		$allClean = true;
		foreach ($this->projection->targets() as $target) {
			$label = ProjectionService::label($target);
			if (!$this->projection->availableFor($target)) {
				$this->logger->warning("facewright: the projection to {$label} is not available, so they are not being kept in step", ['app' => 'facewright_bridge']);
				$allClean = false;
				continue;
			}
			try {
				$report = $this->projection->reconcile(false, null, self::LIMIT, $target);
				$repaired = array_sum($report['repaired']);
				if ($repaired > 0) {
					$this->logger->warning("facewright: repaired {$repaired} difference(s) between {$label} and ours (A {$report['repaired']['A']}, C {$report['repaired']['C']}, D {$report['repaired']['D_missing']}+{$report['repaired']['D_stale']})", ['app' => 'facewright_bridge']);
				}
				$left = $report['A'] + $report['C'] + $report['D_missing'] + $report['D_stale'];
				if ($left > 0) {
					// Something was found: count again rather than trust that the repair got all of it.
					$after = $this->projection->reconcile(true, null, 0, $target);
					$left = $after['A'] + $after['C'] + $after['D_missing'] + $after['D_stale'];
				}
				if ($left > 0) {
					$allClean = false;
					$this->logger->warning("facewright: {$left} difference(s) between {$label} and ours remain after a repair run; the next run continues", ['app' => 'facewright_bridge']);
				}
			} catch (\Throwable $e) {
				// One target failing must not stop the next one from being repaired.
				$allClean = false;
				$this->logger->error("facewright: projection maintenance of {$label} failed: " . $e->getMessage(), ['app' => 'facewright_bridge', 'exception' => $e]);
				$this->projection->markDirty($e->getMessage());
			}
		}
		// The flag is shared: cleared only when every target ended with nothing left and nothing newer had failed.
		if ($allClean) {
			$this->projection->markClean($seq);
		}
	}
}
