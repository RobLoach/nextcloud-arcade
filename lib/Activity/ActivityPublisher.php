<?php

declare(strict_types=1);

namespace OCA\Arcade\Activity;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Service\StateService;
use OCP\Activity\IManager;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Tells the Activity app what was played and saved, so the personal stream
 * can say so.
 *
 * Every entry here is the player's own doing, seen only by the player, so
 * the affected user and the author are one and the same. Publishing is a
 * courtesy on top of gameplay, never a part of it: whatever goes wrong --
 * the Activity app disabled, the event refused -- is logged and swallowed,
 * and the game plays on.
 */
class ActivityPublisher {
	/**
	 * The type of every activity of the app, and the identifier of the
	 * setting that mutes them. The two must match, or the mute would have
	 * nothing to grab.
	 */
	public const TYPE = 'arcade_game';

	/** A session shorter than this says nothing worth a stream entry. */
	private const MIN_SESSION = 60;

	public function __construct(
		private IManager $activityManager,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A game was launched. Fired once per launch, which is exactly one
	 * entry per launch: the launch is the activity.
	 */
	public function gameStarted(string $userId, string $path): void {
		$this->publish($userId, $path, Provider::SUBJECT_GAME_STARTED, []);
	}

	/**
	 * A session ended after playing for a while. Under a minute is a
	 * misclick or a peek, not a session, and gets no entry.
	 */
	public function sessionEnded(string $userId, string $path, int $seconds): void {
		if ($seconds < self::MIN_SESSION) {
			return;
		}
		$this->publish($userId, $path, Provider::SUBJECT_SESSION_ENDED, ['seconds' => $seconds]);
	}

	/**
	 * A save state was written by hand. The auto slot is written every time
	 * a game closes and on a timer besides, which would drown the stream,
	 * so only the numbered slots count.
	 */
	public function stateSaved(string $userId, string $path, int $slot): void {
		if ($slot === StateService::AUTO_SLOT) {
			return;
		}
		$this->publish($userId, $path, Provider::SUBJECT_STATE_SAVED, ['slot' => $slot]);
	}

	/**
	 * @param array<string, int> $extra
	 */
	private function publish(string $userId, string $path, string $subject, array $extra): void {
		try {
			$game = $this->gameParameter($userId, $path);
			$event = $this->activityManager->generateEvent();
			$event->setApp(Application::APP_ID)
				->setType(self::TYPE)
				->setAffectedUser($userId)
				->setAuthor($userId)
				->setTimestamp(time())
				->setSubject($subject, ['game' => $game] + $extra)
				// The object is the game file, so renames reach old entries
				// and the stream of a file shows its plays.
				->setObject('files', $game['id'] ?? 0, $path)
				// The player was there when it happened; a notification
				// about it would only repeat the obvious.
				->setGenerateNotification(false);
			$this->activityManager->publish($event);
		} catch (\Throwable $e) {
			// The stream is decoration; the game must not feel this.
			$this->logger->debug(
				'Could not publish an arcade activity: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e],
			);
		}
	}

	/**
	 * The game as the provider will want it: the file id when the file can
	 * be found, so the entry can point at it, and the name either way.
	 *
	 * @return array{id?: int, name: string, path: string}
	 */
	private function gameParameter(string $userId, string $path): array {
		$name = basename($path);
		try {
			$id = $this->rootFolder->getUserFolder($userId)->get($path)->getId();
			return ['id' => $id, 'name' => $name, 'path' => $path];
		} catch (\Throwable) {
			return ['name' => $name, 'path' => $path];
		}
	}
}
