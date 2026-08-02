<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\BackgroundJob\AccessSyncJob;
use OCA\FacewrightBridge\Service\AccessSyncService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Brings detection rows written before fan-out existed up to date: every file that was detected for
 * its owner alone gets rows for everyone else who can see it.
 *
 * Structurally this is a replay of the mount-added path over every mount that already exists, which
 * is why it needs almost no logic of its own and why the expensive part - resolving who has access -
 * costs one query per mount rather than one per file.
 *
 * Idempotent by construction: it only inserts for users lacking rows and only deletes for users
 * lacking access, and it never calls the ExApp. A second run is a pure-read no-op, so an interrupted
 * run is recovered simply by running it again - there is no resume cursor to remember.
 */
class BackfillAccess extends Command {
	public function __construct(
		private IUserManager $userManager,
		private IUserMountCache $userMountCache,
		private AccessSyncService $accessSync,
		private IJobList $jobList,
		private IRootFolder $rootFolder,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:backfill-access')
			->setDescription('Fan out existing face detections to every user with access, and optionally prune rows for users without it')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything')
			->addOption('user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to these users\' mounts (repeatable)')
			->addOption('mount-root', null, InputOption::VALUE_REQUIRED, 'Limit to a single mount root id - the safe way to make a first live run small')
			->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Files per database round', '500')
			->addOption('prune', null, InputOption::VALUE_NONE, 'Also delete rows for users who no longer have access (off by default)')
			->addOption('background', null, InputOption::VALUE_NONE, 'Enqueue background jobs instead of doing the work now');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		// Off by default on purpose: the first run against a real library should be additive only.
		// If the access computation were wrong, an additive run leaves recoverable duplicates while
		// a pruning one deletes tens of thousands of embeddings that would have to be re-inferred.
		// --dry-run always reports what a pruning run would remove, so the number can be inspected
		// before anyone opts in.
		$prune = (bool)$input->getOption('prune');
		$background = (bool)$input->getOption('background');
		$chunkSize = max(1, (int)$input->getOption('chunk-size'));
		$onlyRoot = $input->getOption('mount-root') === null ? null : (int)$input->getOption('mount-root');

		$users = $this->resolveUsers($input->getOption('user'), $output);
		if ($users === null) {
			return 1;
		}

		$totals = ['mounts' => 0, 'examined' => 0, 'copied' => 0, 'pruned' => 0];
		$touched = [];
		$done = [];
		// Unioned, not summed. Mounts nest - here files/Bilder is shared to Bob and four folders
		// inside it are shared to her separately - so a dry run, which writes nothing to make the
		// second visit a no-op, would otherwise count the same file once per containing mount.
		$dryRunCopy = [];
		$dryRunPrune = [];

		foreach ($users as $user) {
			foreach ($this->userMountCache->getMountsForUser($user) as $mount) {
				$rootId = $this->resolveRootId($mount, $user->getUID());
				if ($rootId === null) {
					continue;
				}
				if ($onlyRoot !== null && $rootId !== $onlyRoot) {
					continue;
				}
				// Consti's home root and Bob's twelve share roots into it are distinct mounts, but
				// two users sharing the same root would otherwise be walked twice.
				$key = $mount->getStorageId() . ':' . $rootId;
				if (isset($done[$key])) {
					continue;
				}
				$done[$key] = true;
				$totals['mounts']++;

				// Printed as we go rather than only at the end, so an interrupted run says where it
				// got to.
				$output->writeln(sprintf(
					'<info>%s</info> %s (storage %d, root %d)',
					$user->getUID(),
					$mount->getMountPoint(),
					$mount->getStorageId(),
					$rootId,
				));

				if ($background) {
					$this->jobList->add(AccessSyncJob::class, [
						'storageId' => $mount->getStorageId(),
						'rootId' => $rootId,
						'afterFileId' => 0,
						'prune' => $prune,
					]);
					continue;
				}

				$after = 0;
				do {
					$result = $this->accessSync->reconcileMount(
						$mount->getStorageId(),
						$rootId,
						$after,
						$chunkSize,
						$dryRun,
						$prune || $dryRun,
					);
					$totals['examined'] += $result['examined'];
					$totals['copied'] += $result['copied'];
					$totals['pruned'] += $result['pruned'];
					$dryRunCopy += $result['dryRunCopy'];
					$dryRunPrune += $result['dryRunPrune'];
					foreach ($result['usersTouched'] as $userId) {
						$touched[$userId] = true;
					}
					$after = $result['lastFileId'] ?? 0;
				} while ($result['examined'] === $chunkSize && $result['lastFileId'] !== null);
			}
		}

		if ($background) {
			$output->writeln("Enqueued access-sync jobs for {$totals['mounts']} mount(s). They run on cron; watch the log for 'access sync' lines.");
			return 0;
		}

		$copiedRows = $dryRun ? array_sum($dryRunCopy) : $totals['copied'];
		$prunedRows = $dryRun ? array_sum($dryRunPrune) : $totals['pruned'];

		$output->writeln('');
		$output->writeln("{$totals['mounts']} mount(s), {$totals['examined']} file(s) examined.");
		$output->writeln(($dryRun ? 'Would copy ' : 'Copied ') . $copiedRows . ' detection row(s).');
		if ($dryRun) {
			$output->writeln("Would prune {$prunedRows} row(s) for users without access.");
		} elseif ($prune) {
			$output->writeln("Pruned {$prunedRows} row(s) for users without access.");
		}
		// Row count alone understates this: each row carries a ~10.7 KB embedding, so a fan-out that
		// reads as "a few tens of thousands of rows" is several hundred megabytes of table growth.
		// Better to learn that before writing than after.
		$output->writeln(sprintf('Approximate table growth: %.1f MB.', $copiedRows * 10700 / 1048576));
		if ($touched !== []) {
			$output->writeln('Users needing a clustering pass: ' . implode(', ', array_keys($touched)));
		}
		if ($dryRun) {
			$output->writeln('<comment>Dry run - nothing was written.</comment>');
		} elseif (!$prune) {
			$output->writeln('<comment>Ran without --prune, so nothing was deleted. Use --dry-run to see how many rows a pruning run would remove.</comment>');
		}
		return 0;
	}

	/**
	 * @param list<string> $userIds
	 *
	 * @return list<IUser>|null null when a named user doesn't exist
	 */
	private function resolveUsers(array $userIds, OutputInterface $output): ?array {
		if ($userIds === []) {
			$users = [];
			$this->userManager->callForSeenUsers(function (IUser $user) use (&$users): void {
				$users[] = $user;
			});
			return $users;
		}

		$users = [];
		foreach ($userIds as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				$output->writeln("<error>No such user: {$userId}</error>");
				return null;
			}
			$users[] = $user;
		}
		return $users;
	}

	/**
	 * The subtree this mount should be reconciled over, or null if it isn't one that can hold
	 * detections.
	 *
	 * A share mount's root is already the shared folder, so it is used as-is. A *home* mount is
	 * different in two ways that both bite: its mount point is "/<uid>/" rather than
	 * "/<uid>/files/", and its root id is the storage root - whose subtree includes files_trashbin
	 * and files_versions. Walking that would reconcile trashed files. The user's files folder is
	 * the root that actually means "this user's library".
	 */
	private function resolveRootId(ICachedMountInfo $mount, string $userId): ?int {
		$mountPoint = rtrim($mount->getMountPoint(), '/');
		if ($mountPoint === '/' . $userId) {
			try {
				return $this->rootFolder->getUserFolder($userId)->getId();
			} catch (\Throwable) {
				return null;
			}
		}
		return str_starts_with($mountPoint . '/', '/' . $userId . '/files/') ? $mount->getRootId() : null;
	}
}
