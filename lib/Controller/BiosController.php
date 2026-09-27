<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\Service\BiosService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Hands out the BIOS files the instance holds, for the players that need
 * one and whose own system folder has not got it, and lets an administrator
 * manage the BIOS files of their own system folder from the settings page.
 * The instance-wide store itself is only filled by occ arcade:bios; here it
 * shows up as the fallback it is.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class BiosController extends ArcadeController {
	// BIOS files are small; the largest asked for is well under a megabyte.
	private const MAX_BIOS_SIZE = 16 * 1024 * 1024;

	public function __construct(
		string $appName,
		IRequest $request,
		private BiosService $biosService,
		protected ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/bios')]
	public function get(string $name = ''): DataDisplayResponse {
		// The user's own system folder first, whatever the casing there,
		// then the store of the instance; a public page has no folder, so
		// only the store answers for it.
		$data = $this->biosService->readFor($this->userId, $name);
		if ($data === null) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($data, Http::STATUS_OK, [
			'Content-Type' => 'application/octet-stream',
		]);
		// Private cache: the bytes may be the user's own.
		$response->cacheFor(24 * 3600, false, true);
		return $response;
	}

	/**
	 * What every system that wants a BIOS has and has not got, seen the
	 * way the player of the asking administrator would see it: their own
	 * system folder first, the store of the instance as the fallback.
	 * Admin-only, so no NoAdminRequired here.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/arcade/bios/status')]
	public function status(): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		return new JSONResponse($this->biosService->statusFor($this->userId));
	}

	/**
	 * Takes one BIOS file, sent as the raw request body, into the system
	 * folder of the asking administrator, and only under a name some core
	 * actually asks for. Admin-only, but a modest limit is cheap insurance:
	 * uploading a full BIOS set one file at a time is a few dozen requests,
	 * so sixty a minute never troubles a real administrator.
	 */
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/bios')]
	public function upload(string $name = ''): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		$canonical = BiosService::canonicalName($name);
		if ($canonical === null) {
			return new JSONResponse(
				['error' => 'No core asks for a file of that name'],
				Http::STATUS_BAD_REQUEST,
			);
		}
		$data = $this->readBody(self::MAX_BIOS_SIZE + 1);
		if (!is_string($data) || $data === '' || strlen($data) > self::MAX_BIOS_SIZE) {
			return new JSONResponse(
				['error' => 'The file is empty or larger than a BIOS could be'],
				Http::STATUS_BAD_REQUEST,
			);
		}
		if (!$this->biosService->storeUpload($this->userId, $canonical, $data)) {
			return new JSONResponse(
				['error' => 'The file could not be stored'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
		return new JSONResponse(['name' => $canonical, 'size' => strlen($data)]);
	}

	/**
	 * Takes a BIOS file back out of the system folder of the asking
	 * administrator, by name. A file that only lives in the instance-wide
	 * store cannot be deleted from here -- that store belongs to
	 * occ arcade:bios -- so the answer is 409 with storeOnly: true, which
	 * the settings page turns into a note rather than an error. Admin-only;
	 * the same generous ceiling as the upload covers clearing a whole set.
	 */
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'DELETE', url: '/arcade/bios')]
	public function remove(string $name = ''): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		$canonical = BiosService::canonicalName($name);
		if ($canonical === null) {
			return new JSONResponse(
				['error' => 'No core asks for a file of that name'],
				Http::STATUS_BAD_REQUEST,
			);
		}
		$result = $this->biosService->deleteFor($this->userId, $canonical);
		if ($result === 'store') {
			return new JSONResponse(['storeOnly' => true], Http::STATUS_CONFLICT);
		}
		if ($result === 'missing') {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([]);
	}

	/**
	 * The raw request body, up to $limit bytes. Overridable so tests can
	 * stand in for php://input, which cannot be written to from a test.
	 */
	protected function readBody(int $limit): string|false {
		return file_get_contents('php://input', length: $limit);
	}
}
