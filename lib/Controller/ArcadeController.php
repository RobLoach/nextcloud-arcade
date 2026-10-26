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
	 * The path of the game a request is about: from the file parameter as
	 * it arrived, or else from f, the same path in base64url.
	 *
	 * The player sends f. ROMs are named like "Kirby's Adventure (USA)",
	 * and a quote with brackets in a query string is what a web application
	 * firewall in front of the server takes for SQL being smuggled in: it
	 * turns the request away before Nextcloud sees it, and the save is
	 * lost. Letters, digits, - and _ give it nothing to object to.
	 */
	protected function romPath(string $file): string {
		if ($file !== '') {
			return $file;
		}
		$encoded = $this->request->getParam('f', '');
		if (!is_string($encoded) || $encoded === '') {
			return '';
		}
		$decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
		return is_string($decoded) && mb_check_encoding($decoded, 'UTF-8') ? $decoded : '';
	}

	/**
	 * What a client uploaded, up to $maxSize bytes: the file sent as the
	 * data field of a form, or else the raw body of the request. Null when
	 * there is nothing, it is too big, or it did not arrive whole.
	 *
	 * The player sends a form. A web application firewall in front of the
	 * server -- ModSecurity with the OWASP Core Rule Set is the common one
	 * -- allows only the body types a form or an API sends, and refuses
	 * application/octet-stream outright: every save was turned away before
	 * Nextcloud saw it. A file in a form is what every browser upload is,
	 * and no rule set refuses that. The raw body is still taken, for
	 * clients that send one.
	 */
	protected function readUpload(int $maxSize): ?string {
		$file = $this->uploadedFile('data');
		if ($file === null) {
			return $this->readBody($maxSize);
		}
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return null;
		}
		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > $maxSize) {
			return null;
		}
		$content = $this->uploadedContent((string)($file['tmp_name'] ?? ''));
		// PHP keeps a file that was cut off short as a partial upload, but
		// the size it says it received is what the content is held to.
		return is_string($content) && strlen($content) === $size ? $content : null;
	}

	/**
	 * A file of the request as PHP received it, null when there is none.
	 *
	 * @return ?array<array-key, mixed>
	 */
	protected function uploadedFile(string $field): ?array {
		$file = $this->request->getUploadedFile($field);
		return is_array($file) && isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file : null;
	}

	/**
	 * What an uploaded file holds, read from where PHP put it -- and only
	 * from there: a path that is not an upload of this request is nothing
	 * to read. Overridable so tests can stand in for it.
	 */
	protected function uploadedContent(string $path): ?string {
		if ($path === '' || !is_uploaded_file($path)) {
			return null;
		}
		$content = file_get_contents($path);
		return $content === false ? null : $content;
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
