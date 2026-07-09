<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Command;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prototype clustering. Incremental nearest-neighbor assignment modeled on Immich's approach
 * rather than Recognize's own batch HDBSCAN (see CLAUDE.md for why): a face joins/starts a
 * cluster once it has at least --min-faces matches (including itself) within --max-distance
 * cosine distance of an existing detection. Defaults are Immich's tuned values for the same
 * buffalo_l model recognAIze uses - a starting point, not yet validated against this library.
 *
 * Simplification vs. Immich: when a core face has no directly-matched cluster, it always
 * starts a new one, rather than also falling back to the single globally-closest clustered
 * face beyond the initial match set. Close enough for threshold experimentation.
 */
class ClusterFaces extends Command {
	public function __construct(private IDBConnection $db) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognaize:cluster')
			->setDescription("Prototype: cluster a user's face detections by embedding similarity")
			->addArgument('user', InputArgument::REQUIRED)
			->addOption('max-distance', null, InputOption::VALUE_REQUIRED, 'Max cosine distance to count as a match', '0.5')
			->addOption('min-faces', null, InputOption::VALUE_REQUIRED, 'Min matches (incl. self) to form/join a cluster', '3')
			->addOption('reset', null, InputOption::VALUE_NONE, 'Clear this user\'s existing cluster assignments first');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');
		$maxDistance = (float)$input->getOption('max-distance');
		$minFaces = (int)$input->getOption('min-faces');

		if ($input->getOption('reset')) {
			$this->resetClusters($userId, $output);
		}

		$detections = $this->loadDetections($userId);
		$output->writeln(sprintf('Loaded %d face detection(s) for %s.', count($detections), $userId));

		$ids = array_keys($detections);
		$newClusters = 0;
		$joined = 0;
		$skipped = 0;

		foreach ($ids as $id) {
			if ($detections[$id]['cluster_id'] !== null) {
				continue;
			}

			$matches = $this->findMatches($detections, $id, $maxDistance);
			// "matches" excludes $id itself here, so +1 mirrors Immich's "includes the face itself".
			if (count($matches) + 1 < $minFaces) {
				$skipped++;
				continue;
			}

			$clusterId = null;
			foreach ($matches as $match) {
				if ($detections[$match['id']]['cluster_id'] !== null) {
					$clusterId = $detections[$match['id']]['cluster_id'];
					break;
				}
			}

			if ($clusterId === null) {
				$clusterId = $this->createCluster($userId);
				$newClusters++;
			} else {
				$joined++;
			}

			$detections[$id]['cluster_id'] = $clusterId;
			foreach ($matches as $match) {
				if ($detections[$match['id']]['cluster_id'] === null) {
					$detections[$match['id']]['cluster_id'] = $clusterId;
				}
			}
		}

		$this->persistAssignments($detections);
		$this->printSummary($output, $detections, $newClusters, $joined, $skipped, $minFaces);

		return 0;
	}

	// recognize_face_detections predates this project on this instance (Nextcloud app
	// uninstalls don't drop tables) and still holds real rows from Recognize's own old
	// face-api.js classifier - 128-dim vectors, not our 512-dim InsightFace ones. Mixing the
	// two in a distance computation would silently corrupt every match, so filter strictly.
	private const EXPECTED_DIMENSIONS = 512;

	/** @return array<int, array{file_id: int, vector: list<float>, cluster_id: ?int}> */
	private function loadDetections(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'face_vector', 'cluster_id')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();

		$detections = [];
		$skippedForeign = 0;
		foreach ($rows as $row) {
			$vector = json_decode($row['face_vector'], true);
			if (!is_array($vector) || count($vector) !== self::EXPECTED_DIMENSIONS) {
				$skippedForeign++;
				continue;
			}
			$detections[(int)$row['id']] = [
				'file_id' => (int)$row['file_id'],
				'vector' => $vector,
				'cluster_id' => $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null,
			];
		}
		if ($skippedForeign > 0) {
			fwrite(STDERR, "Skipped {$skippedForeign} detection(s) with non-InsightFace vector dimensions.\n");
		}
		return $detections;
	}

	/** @return list<array{id: int, distance: float}> sorted closest-first */
	private function findMatches(array $detections, int $id, float $maxDistance): array {
		$matches = [];
		foreach ($detections as $otherId => $other) {
			if ($otherId === $id) {
				continue;
			}
			$distance = $this->cosineDistance($detections[$id]['vector'], $other['vector']);
			if ($distance <= $maxDistance) {
				$matches[] = ['id' => $otherId, 'distance' => $distance];
			}
		}
		usort($matches, fn ($a, $b) => $a['distance'] <=> $b['distance']);
		return $matches;
	}

	private function cosineDistance(array $a, array $b): float {
		// Both vectors are already L2-normalized (recognaize's /detect returns normed_embedding),
		// so cosine similarity is just the dot product.
		$dot = 0.0;
		foreach ($a as $i => $v) {
			$dot += $v * $b[$i];
		}
		return 1.0 - $dot;
	}

	private function createCluster(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('recognize_face_clusters')
			->values([
				'title' => $qb->createNamedParameter(''),
				'user_id' => $qb->createNamedParameter($userId),
			]);
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	private function persistAssignments(array $detections): void {
		foreach ($detections as $id => $d) {
			if ($d['cluster_id'] === null) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('recognize_face_detections')
				->set('cluster_id', $qb->createNamedParameter($d['cluster_id'], IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}

	private function resetClusters(string $userId, OutputInterface $output): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_detections')
			->set('cluster_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('recognize_face_clusters')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		$output->writeln('Reset existing cluster assignments.');
	}

	private function printSummary(OutputInterface $output, array $detections, int $newClusters, int $joined, int $skipped, int $minFaces): void {
		$sizes = [];
		foreach ($detections as $d) {
			if ($d['cluster_id'] !== null) {
				$sizes[$d['cluster_id']] = ($sizes[$d['cluster_id']] ?? 0) + 1;
			}
		}
		arsort($sizes);

		$output->writeln(sprintf(
			'%d new cluster(s), %d face(s) joined an existing cluster, %d face(s) left unclustered (fewer than %d matches).',
			$newClusters,
			$joined,
			$skipped,
			$minFaces,
		));
		foreach ($sizes as $clusterId => $size) {
			$output->writeln("  cluster {$clusterId}: {$size} face(s)");
		}
	}
}
