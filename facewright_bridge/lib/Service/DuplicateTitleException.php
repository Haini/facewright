<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

/**
 * A person's new name is already another of the user's people. A subclass of
 * \InvalidArgumentException so every existing catch still works; callers that can offer "merge
 * instead" (the People page) tell it apart from other bad input by type, not by its message.
 *
 * Carries the other person's id: the page offers the merge with exactly that person instead of finding them again by
 * name (a search capped at 200 results could miss them).
 */
class DuplicateTitleException extends \InvalidArgumentException {
	public function __construct(
		string $message,
		public readonly int $existingId,
	) {
		parent::__construct($message);
	}
}
