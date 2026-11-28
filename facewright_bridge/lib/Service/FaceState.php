<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

/**
 * Where a face stands for one user, as stored in facewright_user_faces.state.
 *
 * The API and js/people.js read a state as a number, the "sentinel" (SENTINEL_* below, or the person's id for
 * ASSIGNED, or null for PENDING); fromSentinel() / toSentinel() translate in both directions.
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

	/** What Memories sees: the states copied to the table it reads. */
	public const VISIBLE = [self::ASSIGNED, self::UNASSIGNED];

	/** The number for UNASSIGNED in the API, in the copy Memories reads and in the People page: excluded from clustering until a human assigns it. */
	public const SENTINEL_UNASSIGNED = -1;
	/** The number for UNCLUSTERABLE: evaluated in a full pass, nothing matched; still in the matching pool, not tried again as a seed. */
	public const SENTINEL_UNCLUSTERABLE = -2;
	/** The number for IGNORED: not a face or not someone to group, set only by a human; clustering skips it and a reset keeps it. */
	public const SENTINEL_IGNORED = -3;

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
			self::SENTINEL_UNASSIGNED => ['state' => self::UNASSIGNED, 'cluster_id' => null],
			self::SENTINEL_UNCLUSTERABLE => ['state' => self::UNCLUSTERABLE, 'cluster_id' => null],
			self::SENTINEL_IGNORED => ['state' => self::IGNORED, 'cluster_id' => null],
			default => throw new \InvalidArgumentException("Unknown cluster_id value {$clusterId}."),
		};
	}

	/**
	 * The number the API and the People page use for a state.
	 *
	 * @throws \InvalidArgumentException for an unknown state, or ASSIGNED without a cluster
	 */
	public static function toSentinel(int $state, ?int $clusterId): ?int {
		return match ($state) {
			self::PENDING => null,
			self::ASSIGNED => $clusterId !== null && $clusterId > 0
				? $clusterId
				: throw new \InvalidArgumentException('An assigned face needs a person.'),
			self::UNASSIGNED => self::SENTINEL_UNASSIGNED,
			self::UNCLUSTERABLE => self::SENTINEL_UNCLUSTERABLE,
			self::IGNORED => self::SENTINEL_IGNORED,
			default => throw new \InvalidArgumentException("Unknown face state {$state}."),
		};
	}
}
