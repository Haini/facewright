<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

/**
 * A person's new name is already another of the user's people. A subclass of
 * \InvalidArgumentException so every existing catch still works; callers that can offer "merge
 * instead" (the People page) tell it apart from other bad input by type, not by its message.
 */
class DuplicateTitleException extends \InvalidArgumentException {
}
