<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * What the controllers of the app share: the user the request is for,
 * and the guard that turns a missing one into a 401.
 */
abstract class ArcadeController extends Controller {
	protected ?string $userId = null;

	/**
	 * The 401 to hand back when there is no user, null when there is one
	 * and the request can go on.
	 */
	protected function requireUser(): ?JSONResponse {
		return $this->userId === null
			? new JSONResponse([], Http::STATUS_UNAUTHORIZED)
			: null;
	}
}
