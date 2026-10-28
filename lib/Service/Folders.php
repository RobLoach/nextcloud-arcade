<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCP\Files\File;
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
	 * Whether a user has anything at a path in their files. Asked before
	 * acting on a path a request named: a path that leads nowhere is a
	 * game the user does not have. A storage that cannot be reached
	 * answers the same way -- there is nothing to act on either way, and
	 * the caller has a plain "no" to turn the request away with.
	 */
	public static function has(IRootFolder $rootFolder, string $userId, string $path): bool {
		try {
			return $rootFolder->getUserFolder($userId)->nodeExists($path);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Whether a file still exists anywhere in a user's storage -- the trash
	 * included, which is the whole point of asking by id rather than by
	 * path. Right after a deletion, a yes only says the trash caught it.
	 *
	 * When in doubt the answer is yes: what hangs on it is whether the
	 * saves of a game are dropped, and the benefit of any doubt goes to
	 * the saves.
	 */
	public static function stillAround(IRootFolder $rootFolder, string $userId, int $fileId): bool {
		try {
			return $rootFolder->getUserFolder($userId)->getParent()->getFirstNodeById($fileId) !== null;
		} catch (\Throwable) {
			return true;
		}
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

	/**
	 * The folder at a path under a folder, made one level at a time where
	 * it is not there yet; null when something on the way is a file.
	 */
	public static function make(Folder $base, string $path): ?Folder {
		$folder = $base;
		foreach (explode('/', trim($path, '/')) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			try {
				$node = $folder->get($segment);
				if (!$node instanceof Folder) {
					return null;
				}
				$folder = $node;
			} catch (NotFoundException) {
				$folder = $folder->newFolder($segment);
			}
		}
		return $folder;
	}

	/**
	 * The MD5 of a file: the storage's own where it keeps one, rather than
	 * reading a save of megabytes in whole for it, and worked out from the
	 * content where it does not.
	 */
	public static function md5Of(File $file): string {
		$hash = $file->hash('md5');
		return is_string($hash) && $hash !== '' ? $hash : md5($file->getContent());
	}
}
