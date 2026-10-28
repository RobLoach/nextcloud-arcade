<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * The save states versions before 0.48.8 kept in the app data, out of the
 * user's sight. Nothing reads them any more: they are only counted, for
 * the maintenance section and occ, and removed -- all at once when asked
 * to, and a game's or a user's when that game or user goes.
 *
 * They were filed in a folder per user, named after a hash of the user id,
 * as <key>-<slot>.state and .png and <key>.srm, the key being the id of
 * the ROM's file.
 */
class LegacyAppDataSaves {
	/** The highest slot those versions had; 0 was the automatic one. */
	private const HIGHEST_SLOT = 6;

	/** @var array<string, ISimpleFolder|false> each user's folder, or false for none */
	private array $userFolders = [];

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
	) {
	}

	/** What a user's folder is named after. */
	public static function keyOf(string $userId): string {
		return hash('sha256', $userId);
	}

	/**
	 * The folders there are, by the key of their user -- the users that
	 * are gone included, which is what the cleanup looks for.
	 *
	 * Through the files of the instance rather than the app data's own
	 * folders, which list files and never the folders beside them.
	 *
	 * @return array<string, Folder>
	 */
	public function userFolders(): array {
		$folders = [];
		foreach ($this->states()?->getDirectoryListing() ?? [] as $node) {
			if ($node instanceof Folder) {
				$folders[$node->getName()] = $node;
			}
		}
		return $folders;
	}

	/**
	 * How much is left, of every user and of nobody. The sizes come from
	 * the file cache; nothing is read.
	 *
	 * @return array{files: int, bytes: int}
	 */
	public function count(): array {
		$files = 0;
		$bytes = 0;
		foreach (self::filesUnder($this->states()) as $file) {
			$files++;
			$bytes += (int)$file->getSize();
		}
		return ['files' => $files, 'bytes' => $bytes];
	}

	/** Remove all of it. Says how many files went. */
	public function deleteAll(): int {
		$states = $this->states();
		$removed = count(self::filesUnder($states));
		foreach ($states?->getDirectoryListing() ?? [] as $node) {
			$node->delete();
		}
		$this->userFolders = [];
		return $removed;
	}

	/** Remove what is kept of one game, under one of its keys. */
	public function deleteGame(string $userId, string $key): void {
		foreach (range(0, self::HIGHEST_SLOT) as $slot) {
			$this->deleteFile($userId, "$key-$slot.state");
			$this->deleteFile($userId, "$key-$slot.png");
		}
		$this->deleteSram($userId, $key);
	}

	/** Remove the battery save of one game. Says whether there was one. */
	public function deleteSram(string $userId, string $key): bool {
		return $this->deleteFile($userId, "$key.srm");
	}

	/** Remove everything kept for a user. */
	public function deleteUser(string $userId): void {
		try {
			$this->appData()->getFolder('states')->getFolder(self::keyOf($userId))->delete();
		} catch (NotFoundException) {
			// Nothing was ever stored for this user.
		}
		unset($this->userFolders[$userId]);
	}

	private function deleteFile(string $userId, string $name): bool {
		$folder = $this->userFolder($userId);
		try {
			if ($folder !== null && $folder->fileExists($name)) {
				$folder->getFile($name)->delete();
				return true;
			}
		} catch (NotFoundException) {
			// Gone between the look and the delete.
		}
		return false;
	}

	private function userFolder(string $userId): ?ISimpleFolder {
		$found = $this->userFolders[$userId] ?? null;
		if ($found !== null) {
			return $found === false ? null : $found;
		}
		try {
			return $this->userFolders[$userId] = $this->appData()->getFolder('states')->getFolder(self::keyOf($userId));
		} catch (NotFoundException) {
			$this->userFolders[$userId] = false;
			return null;
		}
	}

	private function appData(): \OCP\Files\IAppData {
		return $this->appDataFactory->get(Application::APP_ID);
	}

	/** The states folder of the app data, as a folder of the instance's files. */
	private function states(): ?Folder {
		try {
			$node = $this->rootFolder->get($this->rootFolder->getAppDataDirectoryName() . '/' . Application::APP_ID . '/states');
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	/**
	 * Every file under a folder, however deep.
	 *
	 * @return list<File>
	 */
	private static function filesUnder(?Folder $folder): array {
		$files = [];
		foreach ($folder?->getDirectoryListing() ?? [] as $node) {
			if ($node instanceof File) {
				$files[] = $node;
			} elseif ($node instanceof Folder) {
				array_push($files, ...self::filesUnder($node));
			}
		}
		return $files;
	}
}
