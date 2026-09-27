<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\GenericFileException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\LockedException;

/**
 * The BIOS files of the instance, for the systems that ask for one.
 *
 * A few consoles will not start without the file their own firmware lived
 * in, and that file is the one thing a player cannot make for themselves.
 * The player looks in the user's own system folder first and falls back to
 * the store of the instance, so this service knows both: the store, filled
 * by occ arcade:bios, and the system folder of whoever is asking.
 *
 * Only the names the cores actually ask for are kept in the store, so it
 * cannot become a place to put files in general.
 */
class BiosService {
	private const FOLDER = 'bios';

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private SettingsService $settingsService,
	) {
	}

	/**
	 * Every file name any supported system may ask for.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		$names = [];
		foreach (CoreMap::SYSTEMS as $system) {
			foreach ($system['bios'] as $name) {
				$names[$name] = true;
			}
		}
		return array_keys($names);
	}

	public static function isKnown(string $name): bool {
		return in_array($name, self::names(), true);
	}

	/**
	 * The spelling a core asks for, matched without regard to case, or null
	 * when no core asks for a file of that name at all.
	 */
	public static function canonicalName(string $name): ?string {
		foreach (self::names() as $known) {
			if (strcasecmp($known, $name) === 0) {
				return $known;
			}
		}
		return null;
	}

	/**
	 * The file, or null when the instance has not been given it.
	 */
	public function read(string $name): ?string {
		if (!self::isKnown($name)) {
			return null;
		}
		try {
			$folder = $this->folder(false);
			return $folder !== null && $folder->fileExists($name)
				? $folder->getFile($name)->getContent()
				: null;
		} catch (NotFoundException) {
			return null;
		}
	}

	/**
	 * The file as the player should get it: the user's own system folder
	 * first, matched without regard to case, then the store of the
	 * instance. Without a user -- a public page -- only the store is
	 * looked at.
	 */
	public function readFor(?string $userId, string $name): ?string {
		if (!self::isKnown($name)) {
			return null;
		}
		if ($userId !== null) {
			$folder = $this->systemFolder($userId, false);
			if ($folder !== null) {
				try {
					foreach ($folder->getDirectoryListing() as $node) {
						if ($node instanceof File && strcasecmp($node->getName(), $name) === 0) {
							return $node->getContent();
						}
					}
				} catch (NotFoundException|NotPermittedException|GenericFileException|LockedException) {
					// The folder could not be read; the store may still help.
				}
			}
		}
		return $this->read($name);
	}

	/**
	 * @return bool whether the name is one a core asks for
	 */
	public function write(string $name, string $data): bool {
		if (!self::isKnown($name)) {
			return false;
		}
		$folder = $this->folder(true);
		if ($folder === null) {
			return false;
		}
		try {
			$folder->getFile($name)->putContent($data);
		} catch (NotFoundException) {
			$folder->newFile($name, $data);
		}
		return true;
	}

	public function remove(string $name): bool {
		try {
			$folder = $this->folder(false);
			if ($folder === null || !$folder->fileExists($name)) {
				return false;
			}
			$folder->getFile($name)->delete();
			return true;
		} catch (NotFoundException) {
			return false;
		}
	}

	/**
	 * The names the instance has a file for.
	 *
	 * @return list<string>
	 */
	public function held(): array {
		$held = [];
		try {
			$folder = $this->folder(false);
			foreach ($folder?->getDirectoryListing() ?? [] as $file) {
				if (self::isKnown($file->getName())) {
					$held[] = $file->getName();
				}
			}
		} catch (NotFoundException) {
			return [];
		}
		return $held;
	}

	/**
	 * Everything in the store with its size, whether a core asks for it or
	 * not, so an administrator can see strays as well as what is wanted.
	 *
	 * @return array<string, int> name => size in bytes
	 */
	public function stored(): array {
		$stored = [];
		try {
			$folder = $this->folder(false);
			foreach ($folder?->getDirectoryListing() ?? [] as $file) {
				$stored[$file->getName()] = (int)$file->getSize();
			}
		} catch (NotFoundException) {
			return [];
		}
		return $stored;
	}

	/**
	 * What a user's player would actually find, for the settings page:
	 * every file a system asks for, with where it would come from --
	 * the user's own system folder first, the store of the instance
	 * second, just as the player looks for them.
	 *
	 * @return array{
	 *     systems: list<array{
	 *         system: array{id: string, name: string},
	 *         files: list<array{name: string, present: bool, source: ?string, size: int}>,
	 *     }>,
	 * }
	 */
	public function statusFor(string $userId): array {
		$folderFiles = $this->systemFolderListing($userId);
		$stored = $this->stored();
		$systems = [];
		foreach (CoreMap::SYSTEMS as $id => $system) {
			if ($system['bios'] === []) {
				continue;
			}
			$files = [];
			foreach ($system['bios'] as $name) {
				$actual = self::matchIgnoringCase(array_keys($folderFiles), $name);
				if ($actual !== null) {
					$files[] = ['name' => $name, 'present' => true, 'source' => 'folder', 'size' => $folderFiles[$actual]];
				} elseif (array_key_exists($name, $stored)) {
					$files[] = ['name' => $name, 'present' => true, 'source' => 'store', 'size' => $stored[$name]];
				} else {
					$files[] = ['name' => $name, 'present' => false, 'source' => null, 'size' => 0];
				}
			}
			$systems[] = [
				'system' => ['id' => $id, 'name' => $system['label']],
				'files' => $files,
			];
		}
		return ['systems' => $systems];
	}

	/**
	 * Put an uploaded BIOS file into the user's system folder, under the
	 * spelling the core asks for. A copy already lying there under another
	 * casing is deleted rather than renamed: the upload replaces its
	 * content either way, and deleting leaves a single write path instead
	 * of a rename followed by an overwrite.
	 *
	 * @return bool false when the name is not one a core asks for, or the
	 *              folder cannot be written to
	 */
	public function storeUpload(string $userId, string $name, string $data): bool {
		if (!self::isKnown($name)) {
			return false;
		}
		$folder = $this->systemFolder($userId, true);
		if ($folder === null) {
			return false;
		}
		try {
			foreach ($folder->getDirectoryListing() as $node) {
				if (!$node instanceof File || strcasecmp($node->getName(), $name) !== 0) {
					continue;
				}
				if ($node->getName() === $name) {
					$node->putContent($data);
					return true;
				}
				$node->delete();
			}
			$folder->newFile($name, $data);
			return true;
		} catch (NotFoundException|NotPermittedException) {
			return false;
		}
	}

	/**
	 * Take a BIOS file out of the user's system folder, matched without
	 * regard to case.
	 *
	 * @return 'deleted'|'store'|'missing' 'store' when the file only
	 *         exists in the instance-wide store, which belongs to
	 *         occ arcade:bios and is left alone here
	 */
	public function deleteFor(string $userId, string $name): string {
		$folder = $this->systemFolder($userId, false);
		if ($folder !== null) {
			try {
				foreach ($folder->getDirectoryListing() as $node) {
					if ($node instanceof File && strcasecmp($node->getName(), $name) === 0) {
						$node->delete();
						return 'deleted';
					}
				}
			} catch (NotFoundException|NotPermittedException) {
				// The folder could not be read; the store may still know it.
			}
		}
		return self::matchIgnoringCase(array_keys($this->stored()), $name) !== null
			? 'store'
			: 'missing';
	}

	/**
	 * The name spelled as it actually is among $names, or null.
	 *
	 * @param list<string> $names
	 */
	private static function matchIgnoringCase(array $names, string $name): ?string {
		foreach ($names as $actual) {
			if (strcasecmp($actual, $name) === 0) {
				return $actual;
			}
		}
		return null;
	}

	/** The user's system folder as a path, '' when none is set. */
	private function systemFolderPath(string $userId): string {
		$folder = $this->settingsService->getUserSettings($userId)['system_folder'] ?? '';
		return is_string($folder) ? $folder : '';
	}

	/**
	 * The files of the user's system folder, name => size in bytes.
	 *
	 * @return array<string, int>
	 */
	private function systemFolderListing(string $userId): array {
		$folder = $this->systemFolder($userId, false);
		if ($folder === null) {
			return [];
		}
		$files = [];
		try {
			foreach ($folder->getDirectoryListing() as $node) {
				if ($node instanceof File) {
					$files[$node->getName()] = (int)$node->getSize();
				}
			}
		} catch (NotFoundException|NotPermittedException) {
			return [];
		}
		return $files;
	}

	/**
	 * The system folder of the user as a node, made if asked to, or null
	 * when the user has not set one or it cannot be reached.
	 */
	private function systemFolder(string $userId, bool $create): ?Folder {
		$path = $this->systemFolderPath($userId);
		if ($path === '') {
			return null;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Exception) {
			return null;
		}
		try {
			$node = $userFolder->get($path);
		} catch (NotFoundException|NotPermittedException) {
			if (!$create) {
				return null;
			}
			try {
				return $userFolder->newFolder($path);
			} catch (NotPermittedException) {
				return null;
			}
		}
		return $node instanceof Folder ? $node : null;
	}

	private function folder(bool $create): ?ISimpleFolder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $create ? $appData->newFolder(self::FOLDER) : null;
		}
	}
}
