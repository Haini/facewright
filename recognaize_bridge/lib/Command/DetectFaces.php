<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Command;

use OCA\RecognaizeBridge\Service\DetectionService;
use OCA\RecognaizeBridge\Service\PathFilterService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual trigger: calls DetectionService for a single file. Always (re)processes when
 * explicitly invoked this way, unlike the automatic/batch paths, which skip files that
 * already have detections.
 */
class DetectFaces extends Command {
	public function __construct(
		private IRootFolder $rootFolder,
		private DetectionService $detectionService,
		private PathFilterService $pathFilter,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognaize:detect')
			->setDescription("Detect faces in a file via the recognaize ExApp and store results in Recognize's tables")
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user id who can access the file')
			->addArgument('path', InputArgument::REQUIRED, "File path relative to that user's files root");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');
		$path = $input->getArgument('path');

		if (!$this->pathFilter->isIncluded($path)) {
			$output->writeln("<error>Path '{$path}' is not in the configured included paths - skipping.</error>");
			return 1;
		}

		try {
			$node = $this->rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			$output->writeln("<error>No such file for user {$userId}: {$path}</error>");
			return 1;
		}

		$result = $this->detectionService->detect($userId, $path, $node->getId());
		if (!$result['success']) {
			$output->writeln("<error>{$result['error']}</error>");
			return 1;
		}

		$output->writeln("Inserted {$result['inserted']} face detection(s) for {$path} (fileId={$node->getId()}, user={$userId}).");
		return 0;
	}
}
