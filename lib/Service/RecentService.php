<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\Activity\ActivityPublisher;
use OCA\Arcade\Db\PlayMapper;
use OCP\Files\IRootFolder;
use OCP\ITagManager;
use OCP\ITags;

/**
 * What a user played, when, and for how long, wherever it was started from.
 *
 * A favorite is the star of the Files app, kept where Files keeps it: by the
 * id of the file, so a game that is renamed or moved stays a favorite, and a
 * game starred in one place is starred in the other. What a game was played
 * for is ours, and is kept in a table by that same file id, so it survives a
 * rename just as well -- and two sessions ending together both count, which
 * a JSON blob read and written whole could not promise.
 */
class RecentService {
	private const MAX_ENTRIES = 12;
	/** A session longer than this was most likely a forgotten tab. */
	private const MAX_SESSION = 4 * 3600;

	public function __construct(
		private ITagManager $tagManager,
		private IRootFolder $rootFolder,
		private PlayMapper $playMapper,
		private ActivityPublisher $activityPublisher,
	) {
	}

	/**
	 * The games played last, newest first, as ids: what a game is called
	 * and where it lives are the library's to say, and change when it is
	 * renamed or moved.
	 *
	 * @return list<int>
	 */
	public function get(string $userId): array {
		return $this->playMapper->recentFileIds($userId, self::MAX_ENTRIES);
	}

	/**
	 * The ids of the files the user has starred, as keys.
	 *
	 * One query for all of them, so a listing can be marked without asking
	 * about each game in turn.
	 *
	 * @return array<int, true>
	 */
	public function favoriteIds(string $userId): array {
		$favorites = $this->tags($userId)?->getFavorites();
		if (!is_array($favorites)) {
			return [];
		}
		$ids = [];
		foreach ($favorites as $id) {
			$ids[(int)$id] = true;
		}
		return $ids;
	}

	/**
	 * What each game was played for, by the id of its file.
	 *
	 * @return array<int, array<string, int>>
	 */
	public function stats(string $userId): array {
		return $this->playMapper->statsOf($userId);
	}

	public function record(string $userId, string $path): void {
		$id = $this->fileId($userId, $path);
		if ($id === null) {
			return;
		}
		$this->playMapper->recordPlay($userId, $id, time());
		// One launch, one entry: record() fires once per launch, and the
		// launch is the activity.
		$this->activityPublisher->gameStarted($userId, $path);
	}

	/**
	 * Add the length of a session to what a game was played for.
	 */
	public function addPlayTime(string $userId, string $path, int $seconds): void {
		if ($seconds <= 0) {
			return;
		}
		$id = $this->fileId($userId, $path);
		if ($id === null) {
			return;
		}
		$seconds = min($seconds, self::MAX_SESSION);
		$this->playMapper->addSeconds($userId, $id, $seconds, time());
		// The publisher keeps the trivial sessions out of the stream.
		$this->activityPublisher->sessionEnded($userId, $path, $seconds);
	}

	/**
	 * Drop everything counted for a user, for when the user is deleted.
	 */
	public function deleteAllForUser(string $userId): void {
		$this->playMapper->deleteAllForUser($userId);
	}

	/**
	 * @return bool whether the game is a favorite afterwards
	 */
	public function toggleFavorite(string $userId, string $path): bool {
		$id = $this->fileId($userId, $path);
		$tags = $this->tags($userId);
		if ($id === null || $tags === null) {
			return false;
		}
		if (isset($this->favoriteIds($userId)[$id])) {
			$tags->removeFromFavorites($id);
			return false;
		}
		$tags->addToFavorites($id);
		return true;
	}

	/**
	 * The tags of the Files app, which is where a favorite lives. There are
	 * none to load without a user, on a public share for instance.
	 */
	private function tags(string $userId): ?ITags {
		return $this->tagManager->load('files', [], false, $userId);
	}

	private function fileId(string $userId, string $path): ?int {
		try {
			return $this->rootFolder->getUserFolder($userId)->get($path)->getId();
		} catch (\Throwable) {
			return null;
		}
	}
}
