<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;

/**
 * A cache kept in files of the app data, for an instance with no memory
 * cache configured.
 *
 * Without one, Nextcloud hands out a cache that remembers nothing, and the
 * games library was walked again on every request. A file is slower to
 * read than memory, but far quicker than walking a library folder of
 * thousands of games, so it stands in until a memory cache is set up --
 * and is never used once there is one.
 *
 * Each entry is a file named after the hash of its key, holding the key,
 * the value and when it expires. Nothing is ever unserialized into an
 * object: the values are arrays and strings.
 */
final class AppDataCache implements ICache {
	public function __construct(
		private IAppData $appData,
		private string $prefix,
	) {
	}

	public function get($key) {
		$entry = $this->read($this->file($key, false));
		if ($entry === null || $entry['key'] !== $key) {
			return null;
		}
		if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
			$this->remove($key);
			return null;
		}
		return $entry['value'];
	}

	public function set($key, $value, $ttl = 0) {
		$content = serialize([
			'key' => $key,
			'expires' => $ttl > 0 ? time() + $ttl : 0,
			'value' => $value,
		]);
		try {
			$folder = $this->folder(true);
			if ($folder === null) {
				return false;
			}
			$name = self::nameOf($key);
			if ($folder->fileExists($name)) {
				$folder->getFile($name)->putContent($content);
			} else {
				$folder->newFile($name, $content);
			}
			return true;
		} catch (\Throwable) {
			// A cache that cannot be written is one that is asked again.
			return false;
		}
	}

	public function hasKey($key) {
		return $this->get($key) !== null;
	}

	public function remove($key) {
		$file = $this->file($key, false);
		if ($file === null) {
			return false;
		}
		try {
			$file->delete();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public function clear($prefix = '') {
		$folder = $this->folder(false);
		if ($folder === null) {
			return true;
		}
		try {
			if ($prefix === '') {
				$folder->delete();
				return true;
			}
			foreach ($folder->getDirectoryListing() as $file) {
				$entry = $this->read($file);
				if ($entry !== null && str_starts_with($entry['key'], $prefix)) {
					$file->delete();
				}
			}
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Remove the entries that have expired, which are otherwise only found
	 * when they are asked for -- and a library scan is keyed by the state
	 * of the folder, so one that changed is never asked for again. Says
	 * how many went.
	 */
	public function prune(): int {
		$folder = $this->folder(false);
		$removed = 0;
		foreach ($folder?->getDirectoryListing() ?? [] as $file) {
			$entry = $this->read($file);
			if ($entry === null || ($entry['expires'] !== 0 && $entry['expires'] < time())) {
				try {
					$file->delete();
					$removed++;
				} catch (\Throwable) {
					// Gone already, or tried again next week.
				}
			}
		}
		return $removed;
	}

	/**
	 * Remove the expired entries of every cache of the app.
	 */
	public static function pruneAll(IAppData $appData): int {
		$removed = 0;
		foreach (Application::CACHES as $prefix) {
			$removed += (new self($appData, $prefix))->prune();
		}
		return $removed;
	}

	/**
	 * @return ?array{key: string, expires: int, value: mixed}
	 */
	private function read(?ISimpleFile $file): ?array {
		if ($file === null) {
			return null;
		}
		try {
			$entry = unserialize($file->getContent(), ['allowed_classes' => false]);
		} catch (\Throwable) {
			return null;
		}
		if (!is_array($entry) || !is_string($entry['key'] ?? null) || !is_int($entry['expires'] ?? null)) {
			return null;
		}
		return ['key' => $entry['key'], 'expires' => $entry['expires'], 'value' => $entry['value'] ?? null];
	}

	private function file(string $key, bool $create): ?ISimpleFile {
		$folder = $this->folder($create);
		$name = self::nameOf($key);
		try {
			return $folder !== null && $folder->fileExists($name) ? $folder->getFile($name) : null;
		} catch (NotFoundException) {
			return null;
		}
	}

	private function folder(bool $create): ?ISimpleFolder {
		$name = 'cache_' . preg_replace('/[^a-z0-9_-]/i', '_', $this->prefix);
		try {
			return $this->appData->getFolder($name);
		} catch (NotFoundException) {
			return $create ? $this->appData->newFolder($name) : null;
		}
	}

	private static function nameOf(string $key): string {
		return hash('sha256', $key);
	}
}
