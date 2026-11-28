<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCA\FacewrightBridge\Service\ProjectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The safety net under the projection (docs/own-tables.md, Stage E): every writer pushes what it changed to the copy
 * Memories reads right after it commits, and a push that fails only flags the copy as dirty. This job compares the copy
 * with ours hourly and repairs whatever differs. Capped per run; a larger backlog continues on the next. The dirty
 * flag is cleared only if the run ended with nothing left to repair and nothing newer had failed while it ran. Honours
 * `processing_paused`, like every job here.
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
		if (!$this->projection->available()) {
			$this->logger->warning('facewright: the projection is not available, so the copy Memories reads is not being kept in step', ['app' => 'facewright_bridge']);
			return;
		}
		try {
			$report = $this->projection->reconcile(false, null, self::LIMIT);
			$repaired = array_sum($report['repaired']);
			if ($repaired > 0) {
				$this->logger->warning("facewright: repaired {$repaired} difference(s) between the copy and ours (A {$report['repaired']['A']}, C {$report['repaired']['C']}, D {$report['repaired']['D_missing']}+{$report['repaired']['D_stale']})", ['app' => 'facewright_bridge']);
			}
			$left = $report['A'] + $report['C'] + $report['D_missing'] + $report['D_stale'];
			if ($left > 0) {
				// Something was found: count again rather than trust that the repair got all of it.
				$after = $this->projection->reconcile(true, null, 0);
				$left = $after['A'] + $after['C'] + $after['D_missing'] + $after['D_stale'];
			}
			if ($left > 0) {
				$this->logger->warning("facewright: {$left} difference(s) between the copy and ours remain after a repair run; the next run continues", ['app' => 'facewright_bridge']);
				return;
			}
		} catch (\Throwable $e) {
			$this->logger->error('facewright: projection maintenance failed: ' . $e->getMessage(), ['app' => 'facewright_bridge', 'exception' => $e]);
			$this->projection->markDirty($e->getMessage());
			return;
		}
		// Cleared only if nothing newer failed while the run was going.
		$this->projection->markClean($seq);
	}
}
