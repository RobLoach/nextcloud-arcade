<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\Activity\ActivityPublisher;
use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\StateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IRootFolder;
use OCP\IRequest;

/**
 * Save states, unique per user and game, with a fixed number of slots.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class StateController extends ArcadeController {
	// RetroArch save states are a few MB; leave plenty of headroom.
	private const MAX_STATE_SIZE = 64 * 1024 * 1024;
	private const MAX_THUMBNAIL_SIZE = 4 * 1024 * 1024;

	public function __construct(
		string $appName,
		IRequest $request,
		private StateService $stateService,
		private SettingsService $settingsService,
		private ActivityPublisher $activityPublisher,
		private IRootFolder $rootFolder,
		protected ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/states')]
	public function list(string $file = ''): JSONResponse {
		if (!$this->isValidRequest($file, StateService::AUTO_SLOT)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse([
			'slots' => StateService::SLOTS,
			'states' => $this->stateService->list($this->userId, $file),
			'hasSram' => $this->stateService->hasSram($this->userId, $file),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/state')]
	public function get(string $file = '', int $slot = 1): Response {
		if (!$this->isValidRequest($file, $slot)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$state = $this->stateService->load($this->userId, $file, $slot);
		if ($state === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataDownloadResponse($state, basename($file) . '.state', 'application/octet-stream');
	}

	// The fastest autosave interval is 30 seconds, so with a manual saving
	// spree on top the realistic peak is around 6 saves a minute; 60 leaves
	// ten times that before a runaway client is cut off.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/state')]
	public function save(string $file = '', int $slot = 1): JSONResponse {
		if (!$this->isWritableSlot($file, $slot)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$state = $this->readBody(self::MAX_STATE_SIZE);
		if ($state === null) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$this->stateService->save($this->userId, $file, $slot, $state);
		// Only here: a state saved by hand is worth a stream entry, the
		// autosaves and the thumbnail and SRAM writes are not. The
		// publisher additionally keeps the auto slot out.
		$this->activityPublisher->stateSaved($this->userId, $file, $slot);
		return new JSONResponse(['size' => strlen($state)]);
	}

	// Thumbnails load through plain <img> tags, which cannot send the CSRF
	// token header. The route is read-only and still requires a session.
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/state/thumbnail')]
	public function getThumbnail(string $file = '', int $slot = 1): Response {
		if (!$this->isValidRequest($file, $slot)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$thumbnail = $this->stateService->loadThumbnail($this->userId, $file, $slot);
		if ($thumbnail === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataDownloadResponse($thumbnail, 'thumbnail.png', 'image/png');
	}

	// Every state save is followed by at most one thumbnail, so the same
	// generous ceiling as the state endpoint fits here.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/state/thumbnail')]
	public function saveThumbnail(string $file = '', int $slot = 1): JSONResponse {
		if (!$this->isWritableSlot($file, $slot)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$thumbnail = $this->readBody(self::MAX_THUMBNAIL_SIZE);
		if ($thumbnail === null) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$this->stateService->saveThumbnail($this->userId, $file, $slot, $thumbnail);
		return new JSONResponse([]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/sram')]
	public function getSram(string $file = ''): Response {
		if (!$this->isValidRequest($file, StateService::AUTO_SLOT)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$sram = $this->stateService->loadSram($this->userId, $file);
		if ($sram === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataDownloadResponse($sram, basename($file) . '.srm', 'application/octet-stream');
	}

	// SRAM syncs once a minute plus a flush when the page hides or the game
	// closes; even a few tabs at once stay in single digits per minute.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/sram')]
	public function saveSram(string $file = ''): JSONResponse {
		if (!$this->isValidRequest($file, StateService::AUTO_SLOT)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$sram = $this->readBody(self::MAX_STATE_SIZE);
		if ($sram === null) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$this->stateService->saveSram($this->userId, $file, $sram);
		return new JSONResponse(['size' => strlen($sram)]);
	}

	// One battery save per game, behind an armed button: a handful of
	// clicks at most.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'DELETE', url: '/arcade/sram')]
	public function deleteSram(string $file = ''): JSONResponse {
		if (!$this->isPlausibleRequest($file, StateService::AUTO_SLOT)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->stateService->deleteSram($this->userId, $file)) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([]);
	}

	// Deleting is a manual click per slot; clearing every slot of a game is
	// still only a handful of requests.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'DELETE', url: '/arcade/state')]
	public function delete(string $file = '', int $slot = 1): JSONResponse {
		if (!$this->isPlausibleRequest($file, $slot)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->stateService->delete($this->userId, $file, $slot)) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([]);
	}

	/**
	 * Slots of earlier versions can still be read and removed, so that what
	 * they hold is not stranded.
	 *
	 * On top of the shape of the request, the game itself has to be among
	 * the user's files: saves are keyed by the ROM's path, and a path that
	 * exists nowhere would otherwise mint save files for games the user
	 * does not have.
	 *
	 * @psalm-assert-if-true string $this->userId
	 */
	private function isValidRequest(string $file, int $slot): bool {
		return $this->isPlausibleRequest($file, $slot)
			&& Folders::has($this->rootFolder, $this->userId, $file);
	}

	/**
	 * Whether the request could be about a save of this user at all: a
	 * user, a game named, somewhere of their own to keep saves in, and a
	 * slot that exists.
	 *
	 * This is all the DELETE routes ask, and deliberately so: they clean
	 * up after games that are already gone, where isValidRequest would
	 * turn them away. deleteSram and delete both fall back to a hash of
	 * the path when the file id is gone, and the per-game saves folder
	 * outlives the ROM, so removing what a deleted game left behind must
	 * still go through.
	 *
	 * @psalm-assert-if-true string $this->userId
	 */
	private function isPlausibleRequest(string $file, int $slot): bool {
		return $this->userId !== null
			&& $file !== ''
			// Saves live in the files of the user, so there has to be a
			// folder to put them in.
			&& $this->settingsService->getUserSettings($this->userId)['saves_folder'] !== ''
			// Slot 0 is the one written when a game is closed.
			&& $slot >= StateService::AUTO_SLOT
			&& $slot <= StateService::HIGHEST_SLOT;
	}

	/**
	 * @psalm-assert-if-true string $this->userId
	 */
	private function isWritableSlot(string $file, int $slot): bool {
		return $this->isValidRequest($file, $slot) && $slot <= StateService::SLOTS;
	}
}
