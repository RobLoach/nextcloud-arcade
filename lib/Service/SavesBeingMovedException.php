<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

/**
 * A user's saves are being moved to the layout RetroArch keeps by another
 * process, and did not finish in time. Nothing is read or written meanwhile:
 * a game started from the empty new place, and saved there, would leave
 * the real save behind for good.
 */
class SavesBeingMovedException extends \RuntimeException {
}
