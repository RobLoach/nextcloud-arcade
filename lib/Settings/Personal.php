<?php

declare(strict_types=1);

namespace OCA\Arcade\Settings;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Controls;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private SettingsService $settingsService,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$settings = $this->settingsService->forUser($this->userSession->getUser()?->getUID());
		$this->initialState->provideInitialState('settings', $settings);
		Util::addScript(Application::APP_ID, 'arcade-settings');
		Util::addStyle(Application::APP_ID, 'settings');

		return new TemplateResponse(Application::APP_ID, 'settings', [
			'settings' => $settings,
			'buttons' => Controls::BUTTONS,
			'hotkeys' => Controls::HOTKEYS,
			'systems' => CoreMap::SYSTEMS,
			'thumbnailTypes' => SettingsService::THUMBNAIL_LABELS,
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
