<?php

declare(strict_types=1);

namespace OCA\Arcade\BackgroundJob;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Service\SavesLayoutConverter;
use OCA\Arcade\Service\UserFilesystems;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

/**
 * Moves the saves of every user still in the layout of versions up to
 * 0.48.7 to the one RetroArch keeps, with every app running -- which the
 * upgrade does not have. A user who plays first is converted then, by the
 * request, and is passed over here.
 */
class ConvertSavesLayout extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private IUserConfig $userConfig,
		private SavesLayoutConverter $converter,
		private UserFilesystems $userFilesystems,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$moved = 0;
		$kept = 0;
		// Only a user with settings of their own can have a saves folder.
		foreach (array_keys($this->userConfig->getValuesByUsers(Application::APP_ID, 'settings')) as $userId) {
			$userId = (string)$userId;
			try {
				$counts = $this->userFilesystems->forUser($userId, fn (): ?array => $this->converter->convertIfNeeded($userId));
			} catch (\Throwable $e) {
				$this->logger->warning('Could not move the saves of a user to the new layout', ['user' => $userId, 'exception' => $e]);
				continue;
			}
			$moved += $counts[0] ?? 0;
			$kept += $counts[1] ?? 0;
		}
		if ($moved > 0 || $kept > 0) {
			$this->logger->info("Moved $moved save files to the layout RetroArch keeps; left $kept where they were", ['app' => Application::APP_ID]);
		}
	}
}
