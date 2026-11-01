<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\LibraryScanner;
use OCA\Arcade\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class SettingsController extends ArcadeController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settingsService,
		private LibraryScanner $libraryScanner,
		private IRootFolder $rootFolder,
		protected ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/settings')]
	public function get(): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		return new JSONResponse($this->settingsService->getUserSettings($this->userId));
	}

	#[FrontpageRoute(verb: 'POST', url: '/arcade/settings/admin')]
	public function saveAdmin(): JSONResponse {
		return new JSONResponse(
			$this->settingsService->setInstanceDefaults($this->request->getParams()),
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/settings')]
	public function save(): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		$settings = $this->request->getParams();
		return new JSONResponse($this->settingsService->setUserSettings($this->userId, $settings));
	}

	/**
	 * How each folder setting named in the query stands in the files of
	 * whoever asks -- the paths as typed, saved or not, so the settings
	 * can say what is wrong with a folder before anything goes looking
	 * in it. The administration page asks with the administrator's own
	 * files, which is all there is to go on for a path every user reads
	 * in their own.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/settings/folders')]
	public function checkFolders(): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		$result = [];
		foreach (SettingsService::FOLDER_SETTINGS as $key) {
			$path = $this->request->getParam($key);
			if (is_string($path)) {
				$result[$key] = Folders::check($this->rootFolder, (string)$this->userId, $path);
			}
		}
		return new JSONResponse($result);
	}

	/**
	 * Scan the games library of the user now, from beside the library
	 * folder in the personal settings.
	 */
	// Each scan walks the whole library folder: a few a minute is plenty
	// for a button pressed by hand.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 6, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/library/scan')]
	public function scanLibrary(): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		$games = $this->libraryScanner->scan($this->userId);
		$folder = $this->settingsService->getUserSettings($this->userId)['library_folder'];
		if ($games === null) {
			return new JSONResponse(['folder' => $folder], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse(['folder' => $folder, 'games' => $games]);
	}

	/**
	 * Scan the games library of every user, in the background, from beside
	 * the library folder in the administration settings.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/arcade/library/scan/all')]
	public function scanEveryLibrary(): JSONResponse {
		return new JSONResponse(['queued' => $this->libraryScanner->scanEveryone()]);
	}
}
