<?php

declare(strict_types=1);

namespace OCA\Arcade\Listener;

use OCA\Arcade\CoreMap;
use OCA\Arcade\Service\RecentService;
use OCA\Arcade\Service\StateService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUserSession;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Save states outlive the games and the users they belong to otherwise,
 * with no way left of reaching them.
 *
 * @template-implements IEventListener<NodeDeletedEvent|UserDeletedEvent>
 */
class CleanupListener implements IEventListener {
	public function __construct(
		private StateService $stateService,
		private RecentService $recentService,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof UserDeletedEvent) {
				$this->stateService->deleteAllForUser($event->getUser()->getUID());
				// The play records live in a table of ours now, which no
				// longer goes with the user config of the user.
				$this->recentService->deleteAllForUser($event->getUser()->getUID());
			} elseif ($event instanceof NodeDeletedEvent) {
				$this->handleDeletedNode($event);
			}
		} catch (\Throwable $e) {
			// Cleaning up must never get in the way of the deletion itself.
			$this->logger->warning('Could not clean up the save states', ['exception' => $e]);
		}
	}

	private function handleDeletedNode(NodeDeletedEvent $event): void {
		$node = $event->getNode();
		if ($node instanceof Folder) {
			// A folder does not say what was in it; the games it held keep
			// their states until they are deleted one by one.
			return;
		}
		$owner = $node->getOwner();
		if ($owner === null) {
			return;
		}
		$userId = $owner->getUID();
		$path = $node->getPath();
		if (!self::isGameName($node->getName())) {
			return;
		}

		// A game emptied out of the trash is gone for good. Its path there
		// says nothing of where it used to be, but its file id is the one
		// it always had, and that is what its states are filed under. The
		// server does not fire this event for the trash today -- that is
		// what trashItemDeleted() is connected for -- but if it ever does,
		// the answer is the same.
		if (str_starts_with($path, '/' . $userId . '/files_trashbin/')) {
			$this->stateService->deleteAllForFileId($userId, $node->getId());
			return;
		}

		// The states of a game are keyed by its path in the user folder,
		// which is what the path of the node holds after its prefix.
		$prefix = '/' . $userId . '/files/';
		if (!str_starts_with($path, $prefix)) {
			return;
		}

		// A deleted game that went to the trash can come back, with the
		// same file id and the same name, and a player who restores it
		// would not expect to have lost their saves. Whether it went there
		// is what the file id says: the trash keeps it, so a game whose id
		// still leads somewhere in the user's storage is merely trashed,
		// while one whose id leads nowhere -- no trashbin, or a deletion
		// that bypassed it -- is gone, states and all.
		if ($this->isStillAround($userId, $node->getId())) {
			return;
		}
		$this->stateService->deleteAllForGame($userId, substr($path, strlen($prefix) - 1));
	}

	/**
	 * Connected to the preDelete signal of '\OCP\Trashbin': what is about
	 * to leave the trash is gone for good, and its states go with it. The
	 * signal is the only word the server gives about the trash being
	 * emptied or one item being expunged; NodeDeletedEvent never fires for
	 * paths in the trash. It arrives before the deletion, while the node
	 * can still be read for its file id.
	 *
	 * The signal carries a path like "/files_trashbin/files/Mario.nes.d123"
	 * and no user; the trash being emptied is always the acting user's own.
	 * The expiry jobs run with no user at all, so what they expunge is left
	 * to `occ arcade:cleanup`, which knows a gone game from a trashed one.
	 *
	 * @param array<string, mixed> $params
	 */
	public function trashItemDeleted(array $params): void {
		try {
			$path = $params['path'] ?? null;
			$user = $this->userSession->getUser();
			if (!is_string($path) || $path === '' || $user === null) {
				return;
			}
			$node = $this->rootFolder->get('/' . $user->getUID() . '/' . ltrim($path, '/'));
			$this->expunge($user->getUID(), $node);
		} catch (\Throwable $e) {
			// Cleaning up must never get in the way of the deletion itself.
			$this->logger->warning('Could not clean up the save states', ['exception' => $e]);
		}
	}

	/**
	 * Drop the states of every game a node about to be expunged holds: the
	 * node itself, or, for a folder trashed whole, the games inside it --
	 * nothing else will ever speak of them again.
	 */
	private function expunge(string $userId, Node $node): void {
		if ($node instanceof Folder) {
			foreach ($node->getDirectoryListing() as $child) {
				$this->expunge($userId, $child);
			}
			return;
		}
		if (!self::isGameName($node->getName())) {
			return;
		}
		$this->stateService->deleteAllForFileId($userId, $node->getId());
	}

	/**
	 * Whether the file still exists anywhere in its owner's storage. Right
	 * after a deletion that only says one thing: the trash caught it. The
	 * benefit of any doubt goes to the saves.
	 */
	private function isStillAround(string $userId, int $fileId): bool {
		try {
			$home = $this->rootFolder->getUserFolder($userId)->getParent();
			return $home->getFirstNodeById($fileId) !== null;
		} catch (\Throwable) {
			return true;
		}
	}

	/**
	 * Whether a name is one the library would call a game. The trash puts
	 * the hour of the deletion after the name, so what it holds is
	 * "Mario.nes.d1700000000".
	 */
	private static function isGameName(string $name): bool {
		$name = preg_replace('/\.d\d+$/', '', $name) ?? $name;
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		return $extension === 'zip' || isset(CoreMap::extensionSystemMap()[$extension]);
	}
}
