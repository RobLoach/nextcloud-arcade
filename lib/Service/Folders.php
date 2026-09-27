<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * Finding a folder among a user's files, the way every caller wants it:
 * a path that leads nowhere, or to something that is not a folder, is
 * simply no folder.
 */
final class Folders {
	/** The folder at a path relative to the user folder, or null. */
	public static function folderAt(Folder $userFolder, string $path): ?Folder {
		try {
			$node = $userFolder->get($path);
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	/** The same, for a caller that starts from the root folder. */
	public static function forUser(IRootFolder $rootFolder, string $userId, string $path): ?Folder {
		try {
			$node = $rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	/**
	 * The id Nextcloud gave the file at a path in a user's files, null
	 * when nothing can be found there.
	 */
	public static function fileId(IRootFolder $rootFolder, string $userId, string $path): ?int {
		try {
			return $rootFolder->getUserFolder($userId)->get($path)->getId();
		} catch (\Throwable) {
			return null;
		}
	}
}
