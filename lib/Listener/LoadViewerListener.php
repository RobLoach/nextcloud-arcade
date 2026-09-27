<?php

declare(strict_types=1);

namespace OCA\Arcade\Listener;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Service\SettingsService;
use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Registers the Arcade player as a Viewer handler for ROM mimetypes.
 *
 * @template-implements IEventListener<LoadViewer>
 */
class LoadViewerListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private SettingsService $settingsService,
		private IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadViewer) {
			return;
		}
		$this->settingsService->providePlayerState(
			$this->initialState,
			$this->userSession->getUser()?->getUID(),
		);
		Util::addStyle(Application::APP_ID, 'player');
		// Load after the viewer script so OCA.Viewer is available.
		Util::addScript(Application::APP_ID, 'arcade-viewer', 'viewer');
	}
}
