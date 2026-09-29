<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\RecentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;

/**
 * What was played, and what is a favorite.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class RecentController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private RecentService $recentService,
		private IRootFolder $rootFolder,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	// Fired once when a game starts and once when it is left. The writes are
	// cheap database rows, but a scripted flood would still churn the table,
	// so cap it far above any realistic game-hopping pace.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/recent')]
	public function record(string $file = '', int $seconds = 0): JSONResponse {
		if (!$this->isGame($file)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		if ($seconds > 0) {
			// Reported when a game is left, to add to its play time.
			$this->recentService->addPlayTime($this->userId, $file, $seconds);
		} else {
			$this->recentService->record($this->userId, $file);
		}
		return new JSONResponse([]);
	}

	// A heart toggled by hand while browsing; one a second sustained for a
	// whole minute is already beyond any human clicking.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/favorite')]
	public function favorite(string $file = ''): JSONResponse {
		if (!$this->isGame($file)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse([
			'favorite' => $this->recentService->toggleFavorite($this->userId, $file),
		]);
	}

	/**
	 * Only files the user actually has are worth remembering. The same
	 * question the save states ask before they write anything, and the
	 * same answer for a storage that cannot be reached: nothing to
	 * remember, so the request is turned away rather than thrown.
	 *
	 * @psalm-assert-if-true string $this->userId
	 */
	private function isGame(string $file): bool {
		return $this->userId !== null
			&& $file !== ''
			&& Folders::has($this->rootFolder, $this->userId, $file);
	}
}
