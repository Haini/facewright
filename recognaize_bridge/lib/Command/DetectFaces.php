<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Command;

use OCA\AppAPI\PublicFunctions;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual trigger for this phase: calls the recognaize ExApp for a single file and stores its
 * results in Recognize's own oc_recognize_face_detections table. Recognize's schema is an
 * undocumented internal of another app - see CLAUDE.md for the column mapping this mirrors.
 */
class DetectFaces extends Command {
	public function __construct(
		private IRootFolder $rootFolder,
		private IDBConnection $db,
		private PublicFunctions $appAPI,
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

		try {
			$node = $this->rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			$output->writeln("<error>No such file for user {$userId}: {$path}</error>");
			return 1;
		}
		$fileId = $node->getId();

		// recognaize's own inference can take longer than AppAPI's default 3s request timeout.
		$response = $this->appAPI->exAppRequest(
			'recognaize',
			'/detect',
			$userId,
			'POST',
			['path' => $path],
			['timeout' => 30],
		);
		if (!$response instanceof IResponse) {
			$output->writeln('<error>Request to recognaize failed: ' . ($response['error'] ?? 'unknown error') . '</error>');
			return 1;
		}
		if ($response->getStatusCode() !== 200) {
			$output->writeln('<error>recognaize returned HTTP ' . $response->getStatusCode() . ': ' . $response->getBody() . '</error>');
			return 1;
		}

		$data = json_decode($response->getBody(), true);
		$faces = $data['faces'] ?? [];
		$imageWidth = $data['image_width'] ?? null;
		$imageHeight = $data['image_height'] ?? null;
		if (!$imageWidth || !$imageHeight) {
			$output->writeln('<error>recognaize response is missing image dimensions</error>');
			return 1;
		}

		$inserted = 0;
		foreach ($faces as $face) {
			[$x1, $y1, $x2, $y2] = $face['bbox'];
			$qb = $this->db->getQueryBuilder();
			$qb->insert('recognize_face_detections')
				->values([
					'user_id' => $qb->createNamedParameter($userId),
					'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'x' => $qb->createNamedParameter($x1 / $imageWidth),
					'y' => $qb->createNamedParameter($y1 / $imageHeight),
					'width' => $qb->createNamedParameter(($x2 - $x1) / $imageWidth),
					'height' => $qb->createNamedParameter(($y2 - $y1) / $imageHeight),
					'face_vector' => $qb->createNamedParameter(json_encode($face['embedding'])),
				]);
			$qb->executeStatement();
			$inserted++;
		}

		$output->writeln("Inserted {$inserted} face detection(s) for {$path} (fileId={$fileId}, user={$userId}).");
		return 0;
	}
}
