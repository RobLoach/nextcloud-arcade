<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * The manifest.server of RetroArch's Cloud Sync, kept up to date with what
 * the player writes.
 *
 * RetroArch never hashes what is on the server: it reads this one file,
 * a list of every path it syncs with the MD5 of its content, and compares
 * it with what it last synced. A save written here without a word in the
 * manifest is one RetroArch never fetches, so every write and delete of
 * the player is recorded in it.
 *
 * A delete is recorded as the path with an empty hash, the way RetroArch
 * records one itself. Dropping the entry instead reads to RetroArch as a
 * server that lost the file, and it uploads its own copy right back.
 */
class CloudSyncManifest {
	public const FILE = 'manifest.server';

	// How long a write waits for another one of the same user to finish:
	// a battery save sync and an autosave can land in the same moment.
	private const LOCK_TRIES = 50;
	private const LOCK_WAIT_US = 100_000;

	public function __construct(
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Record changes to the files under a sync root.
	 *
	 * @param Folder $root the folder RetroArch syncs with
	 * @param array<string, string> $changes path relative to the root =>
	 *                                       MD5 of the content, or '' when
	 *                                       the file was deleted
	 */
	public function record(Folder $root, array $changes): void {
		if ($changes === []) {
			return;
		}
		$lock = 'arcade/cloudsync/' . $root->getId();
		$locked = $this->lock($lock);
		try {
			$file = $this->manifestOf($root);
			$entries = self::parse($file?->getContent() ?? '');
			if ($entries === null) {
				// Not something RetroArch can read either, but written by
				// somebody, and not this app's to throw away.
				$this->logger->warning('The Cloud Sync manifest cannot be read, so it is left alone', [
					'path' => $file?->getPath(),
				]);
				return;
			}
			foreach ($changes as $path => $hash) {
				// A delete of something never synced leaves nothing to say.
				if ($hash === '' && !isset($entries[$path])) {
					continue;
				}
				$entries[$path] = $hash;
			}
			$content = self::render($entries);
			if ($file === null) {
				$root->newFile(self::FILE, $content);
			} else {
				$file->putContent($content);
			}
		} finally {
			if ($locked) {
				$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/**
	 * What a manifest says, by path. An empty one says nothing; one that
	 * is not a list of paths is null.
	 *
	 * @return ?array<string, string> path => hash, '' for a deleted file
	 */
	public static function parse(string $content): ?array {
		if (trim($content) === '') {
			return [];
		}
		$decoded = json_decode($content, true);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			return null;
		}
		$entries = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry) || !is_string($entry['path'] ?? null) || $entry['path'] === '') {
				continue;
			}
			$hash = $entry['hash'] ?? '';
			$entries[$entry['path']] = is_string($hash) ? $hash : '';
		}
		return $entries;
	}

	/**
	 * A manifest as RetroArch writes one: a list sorted by path, with no
	 * case to the sorting.
	 *
	 * @param array<string, string> $entries path => hash
	 */
	public static function render(array $entries): string {
		uksort($entries, static fn (string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b));
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
		$items = [];
		foreach ($entries as $path => $hash) {
			$items[] = "  {\n"
				. '    "path": ' . json_encode((string)$path, $flags) . ",\n"
				. '    "hash": ' . json_encode($hash, $flags) . "\n"
				. '  }';
		}
		return "[\n" . implode(",\n", $items) . "\n]\n";
	}

	/**
	 * The address RetroArch is given as its Cloud Storage URL: the WebDAV
	 * address of a folder of a user, with the trailing slash it asks for.
	 */
	public static function webdavUrl(IURLGenerator $urlGenerator, string $userId, string $folder): string {
		$path = implode('/', array_map(rawurlencode(...), explode('/', trim($folder, '/'))));
		return $urlGenerator->getAbsoluteURL('/remote.php/dav/files/' . rawurlencode($userId) . '/' . $path . '/');
	}

	private function manifestOf(Folder $root): ?File {
		try {
			$node = $root->get(self::FILE);
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/**
	 * Wait a moment for another write of the same manifest. One that holds
	 * it for longer than that is written over rather than waited out: an
	 * entry lost to a race is put right by the next save, a save refused
	 * is not.
	 */
	private function lock(string $lock): bool {
		for ($try = 0; $try < self::LOCK_TRIES; $try++) {
			try {
				$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
				return true;
			} catch (LockedException) {
				usleep(self::LOCK_WAIT_US);
			}
		}
		$this->logger->warning('The Cloud Sync manifest stayed locked, so it is written without the lock');
		return false;
	}
}
