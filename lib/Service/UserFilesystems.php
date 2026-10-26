<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCP\Files\ISetupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Lets go of a user's files once a walk over every user is done with them.
 *
 * Opening a user's folder sets up every mount they can see -- their home,
 * the shares they were given, external storages, group folders -- and
 * nothing lets go of it before the process ends. A web request only ever
 * opens its own user's, but a background job that goes through everybody
 * would end up holding the mounts of the whole instance, and on a large
 * one run out of memory partway, leaving the rest undone.
 *
 * Tearing down lets go of every user's files at once, not only the one
 * just done with, so it only happens where nobody is signed in: in a
 * background job or an occ command. The same walk asked for from the
 * administration settings runs in the administrator's own request, which
 * still needs their files afterwards, and keeps holding on.
 */
class UserFilesystems {
	public function __construct(
		private ISetupManager $setupManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Run $work for a user, and let go of their files afterwards when that
	 * is safe and something here was what set them up.
	 *
	 * @template T
	 * @param \Closure(): T $work
	 * @return T
	 */
	public function forUser(IUser|string $user, \Closure $work): mixed {
		$user = is_string($user) ? $this->userManager->get($user) : $user;
		$release = $user !== null
			&& $this->userSession->getUser() === null
			&& !$this->setupManager->isSetupComplete($user);
		try {
			return $work();
		} finally {
			if ($release) {
				$this->setupManager->tearDown();
			}
		}
	}
}
