<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\AccessService;
use OCA\FacewrightBridge\Service\DetectionService;
use OCA\FacewrightBridge\Service\PathFilterService;
use OCA\FacewrightBridge\Service\UserSettingsService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual trigger: calls DetectionService for a single file. Always (re)processes when
 * explicitly invoked this way, unlike the automatic/batch paths, which skip files that
 * already have detections.
 *
 * Stays single-user by default even though detection now fans out, because "detect this one file
 * for this one user" is what the argument list promises. --all-users opts into the fan-out the
 * automatic paths do.
 */
class DetectFaces extends Command {
	public function __construct(
		private IRootFolder $rootFolder,
		private DetectionService $detectionService,
		private PathFilterService $pathFilter,
		private UserSettingsService $userSettings,
		private AccessService $accessService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:detect')
			->setDescription("Detect faces in a file via the facewright ExApp and store results in Recognize's tables")
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user id who can access the file')
			->addArgument('path', InputArgument::REQUIRED, "File path relative to that user's files root")
			->addOption('all-users', null, InputOption::VALUE_NONE, 'Write rows for every user with access to the file, not just the named one');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');
		$path = $input->getArgument('path');

		if (!$this->userSettings->isEnabled($userId)) {
			$output->writeln("<error>User {$userId} has not enabled face recognition - skipping.</error>");
			return 1;
		}

		if (!$this->pathFilter->isIncluded($userId, $path)) {
			$output->writeln("<error>Path '{$path}' is not in {$userId}'s included paths - skipping.</error>");
			return 1;
		}

		try {
			$node = $this->rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			$output->writeln("<error>No such file for user {$userId}: {$path}</error>");
			return 1;
		}

		$targets = $input->getOption('all-users')
			? $this->accessService->getUsersWithAccess($node->getId())
			: null;

		$result = $this->detectionService->detect($userId, $path, $node->getId(), $targets);
		if (!$result['success']) {
			$output->writeln("<error>{$result['error']}</error>");
			return 1;
		}

		$output->writeln(
			"Found {$result['faces']} face(s) in {$path} (fileId={$node->getId()}); "
			. "inserted {$result['inserted']} row(s) across {$result['users']} user(s)."
		);
		return 0;
	}
}
