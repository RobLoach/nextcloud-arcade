<?php

declare(strict_types=1);

namespace OCA\Arcade\Settings;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\CoreOptions;
use OCA\Arcade\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The folders new users start with, and the options of the cores, which
 * hold for everybody playing them.
 */
class Admin implements ISettings {
	public function __construct(
		private SettingsService $settingsService,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'arcade-settings');
		Util::addStyle(Application::APP_ID, 'settings');

		return new TemplateResponse(Application::APP_ID, 'admin', [
			'defaults' => $this->settingsService->getInstanceDefaults(),
			'coreOptions' => CoreOptions::OPTIONS,
			'systemsByCore' => CoreOptions::systemsByCore(),
			'storedCoreOptions' => $this->settingsService->getCoreOptions(),
			'systems' => CoreMap::SYSTEMS,
			'thumbnailTypes' => SettingsService::THUMBNAIL_LABELS,
			'storedThumbnailTypes' => $this->settingsService->getThumbnailTypes(),
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
		if ($user === null) {
			return '';
		}
		$folder = $this->settingsService->getUserSettings($user->getUID())['system_folder'] ?? '';
		return is_string($folder) ? $folder : '';
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
