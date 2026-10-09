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
	 * The request body as it arrived, up to $limit bytes. The one place
	 * php://input is read, and overridable so tests can stand in for it:
	 * it cannot be written to from a test.
	 */
	protected function rawBody(int $limit): string|false {
		return file_get_contents('php://input', length: $limit);
	}

	/**
	 * The request body, up to $maxSize bytes: null when it is empty, too
	 * big to fit, or shorter than the client said it would be.
	 */
	protected function readBody(int $maxSize): ?string {
		// Absent on a chunked request, and not every client is honest, so
		// it is taken as a claim rather than as the length.
		$promised = $this->request->getHeader('Content-Length');
		$announced = ctype_digit($promised) ? (int)$promised : null;
		// Said to be too big: turned away before the body is read rather
		// than after. A client announcing a gigabyte would otherwise have
		// the whole ceiling pulled off the socket and held in memory
		// first, only to be refused on the far side of it.
		if ($announced !== null && $announced > $maxSize) {
			return null;
		}
		// One byte past the ceiling, so a body over it without saying so
		// is still caught. Not capped at what was announced: a body may
		// honestly arrive longer than that, and capping would quietly
		// truncate it to the claim.
		$body = $this->rawBody($maxSize + 1);
		if ($body === false || $body === '' || strlen($body) > $maxSize) {
			return null;
		}
		// A client that goes away mid-upload leaves a body that is merely
		// short, and a save state is a file where short cannot be told
		// from wrong: written out, it replaces a working save with a
		// truncated one that the core will not load. The length the client
		// promised is what tells the two apart.
		//
		// Only a body shorter than promised is turned away. A longer one
		// means something between the two of us rewrote the request --
		// decompressed it, say -- which is not the accident being guarded
		// against, and not worth failing an honest save over.
		if ($announced !== null && $announced > strlen($body)) {
			return null;
		}
		return $body;
	}
}
