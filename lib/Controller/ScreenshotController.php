<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\ThumbnailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;

/**
 * The screenshots taken of a game, when a screenshots folder is set.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class ScreenshotController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settingsService,
		private ThumbnailService $thumbnailService,
		private IRootFolder $rootFolder,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/screenshots')]
	public function list(string $file = ''): JSONResponse {
		if ($this->userId === null || $file === '') {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$folder = $this->getScreenshotsFolder();
		if ($folder === null) {
			return new JSONResponse(['folder' => '', 'screenshots' => []]);
		}
		return new JSONResponse([
			'folder' => $this->settingsService->getUserSettings($this->userId)['screenshots_folder'],
			'screenshots' => $this->thumbnailService->screenshotsFor($folder, basename($file)),
		]);
	}

	// Uploads go over WebDAV and count against the user quota; only the
	// delete writes through the app. Emptying a big gallery one click at a
	// time still stays well under one a second.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'DELETE', url: '/arcade/screenshots')]
	public function delete(int $fileId = 0): JSONResponse {
		if ($this->userId === null || $fileId === 0) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$folder = $this->getScreenshotsFolder();
		if ($folder === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		// Look the file up inside the screenshots folder, so only those can
		// be deleted through here.
		$node = $folder->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$node->delete();
		return new JSONResponse([]);
	}

	private function getScreenshotsFolder(): ?Folder {
		$path = $this->settingsService->getUserSettings((string)$this->userId)['screenshots_folder'];
		if ($path === '') {
			return null;
		}
		return Folders::forUser($this->rootFolder, (string)$this->userId, $path);
	}
}
