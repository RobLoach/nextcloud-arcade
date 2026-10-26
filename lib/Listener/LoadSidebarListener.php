<?php

declare(strict_types=1);

namespace OCA\Arcade\Listener;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Service\SettingsService;
use OCA\Files\Event\LoadSidebar;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Puts the Arcade tab into the sidebar of the Files app, where a ROM shows
 * what the app knows about it.
 *
 * @template-implements IEventListener<LoadSidebar>
 */
class LoadSidebarListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private IUserSession $userSession,
		private SettingsService $settingsService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadSidebar) {
			return;
		}
		// The mimetypes the tab appears for. The Viewer listener provides
		// the same state; whichever runs second writes the same value.
		$this->initialState->provideInitialState('systems', CoreMap::SYSTEMS);
		// A zip or a .bin carries no type of its own to tell a game by, so
		// the tab also goes by where it is: in the user's games library.
		$userId = $this->userSession->getUser()?->getUID();
		$this->initialState->provideInitialState(
			'libraryFolder',
			$userId === null ? '' : $this->settingsService->getUserSettings($userId)['library_folder'],
		);
		Util::addStyle(Application::APP_ID, 'files');
		// Load after the Files app script so OCA.Files.Sidebar is there.
		Util::addScript(Application::APP_ID, 'arcade-files', 'files');
	}
}
