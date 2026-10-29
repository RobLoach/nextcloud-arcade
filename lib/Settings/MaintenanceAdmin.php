<?php

declare(strict_types=1);

namespace OCA\Arcade\Settings;

use OCA\Arcade\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The maintenance tasks of the administration settings, as a form of their
 * own so they come last on the page: after the folders and core options of
 * Admin (50), and after the box art of DeclarativeAdmin (60), which a
 * section of the Admin form could not come after.
 */
class MaintenanceAdmin implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'arcade-settings');
		Util::addStyle(Application::APP_ID, 'variables');
		Util::addStyle(Application::APP_ID, 'settings');
		return new TemplateResponse(Application::APP_ID, 'maintenance');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 90;
	}
}
