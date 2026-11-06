<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\Exception as DbException;

/**
 * Runs an operation again when the database rolls it back as a deadlock victim or a lock wait times out. The
 * operation must be safe to repeat: one statement, or one transaction that the caller starts from the outside.
 * The pause is random, so that what just collided does not collide again in step.
 */
final class DeadlockRetry {
	private const MAX_TRIES = 6;

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public static function run(callable $operation): mixed {
		for ($attempt = 1;; $attempt++) {
			try {
				return $operation();
			} catch (DbException $e) {
				$retryable = in_array($e->getReason(), [DbException::REASON_DEADLOCK, DbException::REASON_LOCK_WAIT_TIMEOUT], true);
				if (!$retryable || $attempt >= self::MAX_TRIES) {
					throw $e;
				}
				usleep(random_int(20_000, 120_000) * $attempt);
			}
		}
	}
}
