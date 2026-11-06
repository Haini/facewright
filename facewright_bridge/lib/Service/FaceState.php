<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

/**
 * Where a face stands for one user, as stored in facewright_user_faces.state.
 *
 * Recognize's table has no such column; it overloads cluster_id with sentinel values, and the
 * constants on ClusteringService (UNASSIGNED, UNCLUSTERABLE, IGNORED) are those values. This class is
 * the translation in both directions, so the rest of the code can stop reasoning in sentinels once the
 * copies end (docs/own-tables.md, Stage D).
 *
 * cluster_id is set if and only if the state is ASSIGNED.
 */
final class FaceState {
	/** Not looked at yet: cluster_id NULL. */
	public const PENDING = 0;
	/** In a person: cluster_id > 0. */
	public const ASSIGNED = 1;
	/** Taken out of its person, or its person deleted: cluster_id -1. Memories lists these. */
	public const UNASSIGNED = 2;
	/** Evaluated in a full pass and matched nothing: cluster_id -2. */
	public const UNCLUSTERABLE = 3;
	/** Marked "not a face" by a human: cluster_id -3. */
	public const IGNORED = 4;

	/** What Memories sees: the states copied to Recognize's tables. */
	public const VISIBLE = [self::ASSIGNED, self::UNASSIGNED];

	/**
	 * @return array{state: int, cluster_id: ?int} cluster_id is only non-null for ASSIGNED
	 * @throws \InvalidArgumentException for a cluster_id that is none of the above (0, or below -3)
	 */
	public static function fromSentinel(?int $clusterId): array {
		if ($clusterId === null) {
			return ['state' => self::PENDING, 'cluster_id' => null];
		}
		if ($clusterId > 0) {
			return ['state' => self::ASSIGNED, 'cluster_id' => $clusterId];
		}
		return match ($clusterId) {
			ClusteringService::UNASSIGNED => ['state' => self::UNASSIGNED, 'cluster_id' => null],
			ClusteringService::UNCLUSTERABLE => ['state' => self::UNCLUSTERABLE, 'cluster_id' => null],
			ClusteringService::IGNORED => ['state' => self::IGNORED, 'cluster_id' => null],
			default => throw new \InvalidArgumentException("Unknown cluster_id value {$clusterId}."),
		};
	}

	/**
	 * The value Recognize's table, and the API, use for a state.
	 *
	 * @throws \InvalidArgumentException for an unknown state, or ASSIGNED without a cluster
	 */
	public static function toSentinel(int $state, ?int $clusterId): ?int {
		return match ($state) {
			self::PENDING => null,
			self::ASSIGNED => $clusterId !== null && $clusterId > 0
				? $clusterId
				: throw new \InvalidArgumentException('An assigned face needs a person.'),
			self::UNASSIGNED => ClusteringService::UNASSIGNED,
			self::UNCLUSTERABLE => ClusteringService::UNCLUSTERABLE,
			self::IGNORED => ClusteringService::IGNORED,
			default => throw new \InvalidArgumentException("Unknown face state {$state}."),
		};
	}
}
