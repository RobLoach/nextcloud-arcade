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

	/** The folders under the root that the player keeps, and so answers for. */
	private const SCOPE = ['saves', 'states'];

	// How far below saves/ and states/ a file is looked for: the core, and
	// the content directory RetroArch can sort by as well.
	private const MAX_DEPTH = 3;

	public function __construct(
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Record changes to the files under a sync root. A change the manifest
	 * already says is no change, and leaves the manifest unwritten.
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
		$this->update($root, function (array $entries) use ($changes): array {
			foreach ($changes as $path => $hash) {
				// A delete of something never synced leaves nothing to say.
				if ($hash === '' && !isset($entries[$path])) {
					continue;
				}
				$entries[$path] = $hash;
			}
			return $entries;
		});
	}

	/**
	 * Bring the manifest in line with what saves/ and states/ actually
	 * hold, for the files that got there some other way than through the
	 * player -- the desktop client, an upload in Files -- and for an entry
	 * lost when RetroArch wrote the manifest over one of the player's.
	 *
	 * A file the manifest already has, and that has not been touched since
	 * the manifest was last written, is taken at its word; the rest are
	 * hashed. Asked to be thorough, every file is. A file that is gone is
	 * recorded as deleted, so RetroArch deletes its own copy too. What the
	 * manifest says of anything else -- RetroArch's configs, say -- is left
	 * as it is.
	 *
	 * @param Folder $root the folder RetroArch syncs with
	 * @param bool $full whether to hash every file
	 * @return array{recorded: int, removed: int} how many files were
	 *                                             recorded, and how many
	 *                                             recorded as deleted
	 * @throws \RuntimeException when the manifest cannot be read
	 */
	public function reconcile(Folder $root, bool $full = false): array {
		$counts = ['recorded' => 0, 'removed' => 0];
		$this->update($root, function (array $entries, ?File $manifest) use ($root, $full, &$counts): array {
			$since = $full || $manifest === null ? null : $manifest->getMTime();
			$seen = [];
			foreach (self::SCOPE as $kind) {
				foreach ($this->filesUnder($root, $kind) as $path => $file) {
					$seen[$path] = true;
					$known = $entries[$path] ?? '';
					if ($since !== null && $known !== '' && $file->getMTime() <= $since) {
						continue;
					}
					$hash = (string)$file->hash('md5');
					if ($hash !== $known) {
						$entries[$path] = $hash;
						$counts['recorded']++;
					}
				}
			}
			foreach ($entries as $path => $hash) {
				$path = (string)$path;
				if ($hash !== '' && !isset($seen[$path]) && self::inScope($path)) {
					$entries[$path] = '';
					$counts['removed']++;
				}
			}
			return $entries;
		}, true);
		return $counts;
	}

	private static function inScope(string $path): bool {
		foreach (self::SCOPE as $kind) {
			if (str_starts_with($path, "$kind/")) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every file under a folder of the root, by its path from the root.
	 *
	 * @return array<string, File>
	 */
	private function filesUnder(Folder $root, string $path, int $depth = 0): array {
		$folder = Folders::folderAt($root, $path);
		$files = [];
		foreach ($folder?->getDirectoryListing() ?? [] as $node) {
			$name = $node->getName();
			if ($name === '' || $name[0] === '.') {
				continue;
			}
			if ($node instanceof File) {
				$files["$path/$name"] = $node;
			} elseif ($node instanceof Folder && $depth < self::MAX_DEPTH) {
				$files += $this->filesUnder($root, "$path/$name", $depth + 1);
			}
		}
		return $files;
	}

	/**
	 * Read the manifest, change it, and write it back if anything changed,
	 * holding the lock throughout.
	 *
	 * @param callable(array<string, string>, ?File): array<string, string> $change
	 * @param bool $strict whether a manifest that cannot be read is an error
	 *                     for the caller, rather than a warning in the log
	 */
	private function update(Folder $root, callable $change, bool $strict = false): void {
		$lock = 'arcade/cloudsync/' . $root->getId();
		$locked = $this->lock($lock);
		try {
			$file = $this->manifestOf($root);
			$entries = self::parse($file?->getContent() ?? '');
			if ($entries === null) {
				if ($strict) {
					throw new \RuntimeException('The Cloud Sync manifest cannot be read');
				}
				// Not something RetroArch can read either, but written by
				// somebody, and not this app's to throw away.
				$this->logger->warning('The Cloud Sync manifest cannot be read, so it is left alone', [
					'path' => $file?->getPath(),
				]);
				return;
			}
			$changed = $change($entries, $file);
			// Written only when it says something new: every write is a new
			// version of the file, and a battery save synced once a minute
			// would otherwise make one a minute of a list that never changed.
			if ($changed === $entries) {
				return;
			}
			$content = self::render($changed);
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
