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

	/**
	 * The raw request body, up to $maxSize bytes: null when it is empty
	 * or too big to fit. Overridable so tests can stand in for
	 * php://input, which cannot be written to from a test.
	 */
	protected function readBody(int $maxSize): ?string {
		$body = file_get_contents('php://input', length: $maxSize + 1);
		if ($body === false || $body === '' || strlen($body) > $maxSize) {
			return null;
		}
		return $body;
	}
}
