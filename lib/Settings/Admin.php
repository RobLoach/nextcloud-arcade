<?php

declare(strict_types=1);

namespace OCA\Arcade\Settings;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\CoreOptions;
use OCA\Arcade\Service\BiosService;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * The folders new users start with, and the options of the cores, which
 * hold for everybody playing them.
 */
class Admin implements ISettings {
	public function __construct(
		private SettingsService $settingsService,
		private BiosService $biosService,
		private IUserSession $userSession,
		private LibraryService $libraryService,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'arcade-settings');
		Util::addStyle(Application::APP_ID, 'variables');
		Util::addStyle(Application::APP_ID, 'settings');

		return new TemplateResponse(Application::APP_ID, 'admin', [
			'defaults' => $this->settingsService->getInstanceDefaults(),
			// What an empty field actually means, for the hint inside it.
			'fallbacks' => $this->settingsService->folderFallbacks(),
			// The ends of the sliders that bound a scan.
			'limits' => SettingsService::INSTANCE_ONLY,
			'coreOptions' => CoreOptions::OPTIONS,
			'systemsByCore' => CoreOptions::systemsByCore(),
			'storedCoreOptions' => $this->settingsService->getCoreOptions(),
			'systems' => CoreMap::SYSTEMS,
			'thumbnailTypes' => SettingsService::THUMBNAIL_LABELS,
			'storedThumbnailTypes' => $this->settingsService->getThumbnailTypes(),
			'pictureSamples' => $this->pictureSamples(),
			// The BIOS section manages this folder, so without one there
			// is nothing to manage and the template leaves it out.
			'systemFolder' => $this->systemFolder(),
		]);
	}

	/**
	 * The resolved system folder of the administrator looking at the page:
	 * their own setting, or the instance default, or '' when neither says
	 * anything.
	 */
	public function systemFolder(): string {
		$user = $this->userSession->getUser();
		return $user === null ? '' : $this->biosService->systemFolderPath($user->getUID());
	}

	/**
	 * A picture of each kind for every system, out of the thumbnails folder
	 * of the administrator looking at the page -- previews are theirs to
	 * see, nobody else's -- or null when they have none to show.
	 *
	 * @return array{samples: array<string, array<string, int>>, version: string}|null
	 */
	public function pictureSamples(): ?array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}
		$userId = $user->getUID();
		$settings = $this->settingsService->getUserSettings($userId);
		if ((string)($settings['thumbnails_folder'] ?? '') === '') {
			return null;
		}
		// A storage that is down costs the samples, never the page.
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			return [
				'samples' => $this->libraryService->thumbnailSamples($userId, $userFolder, $settings),
				'version' => $this->libraryService->thumbnailsVersion($userId, $userFolder, $settings),
			];
		} catch (\Throwable $e) {
			$this->logger->debug('Could not look for sample pictures', ['exception' => $e]);
			return null;
		}
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
