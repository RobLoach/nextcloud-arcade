<?php

declare(strict_types=1);

namespace OCA\Arcade\Settings;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Controls;
use OCA\Arcade\Service\CloudSyncManifest;
use OCA\Arcade\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private SettingsService $settingsService,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId = $this->userSession->getUser()?->getUID();
		$settings = $this->settingsService->forUser($userId);
		$this->initialState->provideInitialState('settings', $settings);
		Util::addScript(Application::APP_ID, 'arcade-settings');
		Util::addStyle(Application::APP_ID, 'settings');

		return new TemplateResponse(Application::APP_ID, 'settings', [
			'settings' => $settings,
			// What an empty field leaves in force, from the one place that
			// knows: the page used to spell "/Games" out for itself, which
			// is a second answer to a question the app already answers.
			'fallbacks' => $this->settingsService->folderFallbacks(),
			'buttons' => Controls::BUTTONS,
			'hotkeys' => Controls::HOTKEYS,
			// Where RetroArch finds the saves folder, for its Cloud Sync.
			'cloudSyncUrl' => $userId === null || $settings['saves_folder'] === ''
				? ''
				: CloudSyncManifest::webdavUrl($this->urlGenerator, $userId, $settings['saves_folder']),
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
