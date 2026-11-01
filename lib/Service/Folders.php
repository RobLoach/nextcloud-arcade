<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

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

	/** What is wrong with a folder setting, for the settings to say. */
	public const OK = 'ok';
	public const INVALID = 'invalid';
	public const MISSING = 'missing';
	public const NOT_A_FOLDER = 'file';
	public const UNREADABLE = 'unreadable';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * How a folder setting stands in a user's files: OK, or what is
	 * wrong with it. An empty path is OK -- it turns the feature off, or
	 * leaves the default in force -- and a path with ".." in it is one
	 * the settings would refuse to save at all.
	 */
	public static function check(IRootFolder $rootFolder, string $userId, string $path): string {
		$path = trim(trim($path), '/');
		if ($path === '') {
			return self::OK;
		}
		if (str_contains($path, '..')) {
			return self::INVALID;
		}
		try {
			$node = $rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			return self::MISSING;
		} catch (NotPermittedException) {
			return self::UNREADABLE;
		} catch (\Throwable) {
			return self::UNAVAILABLE;
		}
		if (!$node instanceof Folder) {
			return self::NOT_A_FOLDER;
		}
		try {
			return $node->isReadable() ? self::OK : self::UNREADABLE;
		} catch (\Throwable) {
			return self::UNAVAILABLE;
		}
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
