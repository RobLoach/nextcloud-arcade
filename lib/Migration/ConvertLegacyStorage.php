<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Db\PlayMapper;
use OCA\Arcade\Service\Folders;
use OCP\Config\IUserConfig;
use OCP\Files\IRootFolder;
use OCP\ITagManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Brings what older versions of the app left behind over to where it lives
 * now, once, at upgrade -- so no request ever has to look in the old places
 * again.
 *
 * Older versions kept the play records and favorites as JSON blobs of the
 * user config; both are converted here.
 *
 * It used to convert the save states of the app data as well, to the names
 * and folders later versions read them under. Save states are not read
 * from the app data at all any more -- they live in the saves folder of
 * each user -- so that conversion was work done on every upgrade for
 * files nothing would open. What is left there is reported and removed
 * by the maintenance section of the administration settings instead.
 *
 * The step is idempotent: everything is checked before it is touched, so a
 * partial earlier run only leaves less to do. Nothing that cannot be
 * converted is ever deleted or overwritten -- it stays exactly where it is.
 *
 * @psalm-suppress UnusedClass
 */
class ConvertLegacyStorage implements IRepairStep {
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserConfig $userConfig,
		private ITagManager $tagManager,
		private PlayMapper $playMapper,
	) {
	}

	public function getName(): string {
		return 'Convert what older versions of Arcade left behind';
	}

	public function run(IOutput $output): void {
		// The plays go first, so a favorite's counts cannot shadow them.
		$this->convertPlays($output);
		$this->convertFavorites($output);
	}

	/**
	 * The plays used to be two JSON blobs of the user config: the counts
	 * under 'stats', the order under 'recent'. They go into the table, and
	 * the blobs go. A row that is already there has counted on since, and
	 * wins.
	 */
	private function convertPlays(IOutput $output): void {
		$stats = $this->userConfig->getValuesByUsers(Application::APP_ID, 'stats');
		$recent = $this->userConfig->getValuesByUsers(Application::APP_ID, 'recent');
		$userIds = array_unique([...array_keys($stats), ...array_keys($recent)]);
		foreach ($userIds as $userId) {
			$userId = (string)$userId;
			$userStats = $stats[$userId] ?? null;
			$userRecent = $recent[$userId] ?? null;
			$this->importPlays(
				$userId,
				is_string($userStats) ? $userStats : '',
				is_string($userRecent) ? $userRecent : '',
			);
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'stats');
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'recent');
		}
		if ($userIds !== []) {
			$output->info('Brought the play records of ' . count($userIds) . ' users into the table.');
		}
	}

	private function importPlays(string $userId, string $storedStats, string $storedRecent): void {
		$stats = json_decode($storedStats, true);
		$stats = is_array($stats) ? $stats : [];
		foreach ($stats as $id => $counted) {
			$id = (int)$id;
			if ($id === 0 || !is_array($counted)) {
				continue;
			}
			$this->playMapper->importPlay(
				$userId,
				$id,
				(int)($counted['plays'] ?? 0),
				(int)($counted['seconds'] ?? 0),
				(int)($counted['time'] ?? 0),
			);
		}

		// A game on the recent list was played even if its counts were
		// trimmed away; its place in the order is kept by spacing the
		// moments just below now.
		$recent = json_decode($storedRecent, true);
		$now = time();
		foreach (is_array($recent) ? array_values($recent) : [] as $index => $entry) {
			$id = (int)(is_array($entry) ? ($entry['id'] ?? 0) : 0);
			if ($id === 0 || isset($stats[$id]) || isset($stats[(string)$id])) {
				continue;
			}
			$this->playMapper->importPlay($userId, $id, 1, 0, $now - $index);
		}
	}

	/**
	 * Favorites used to be a list of ours, kept by path. They are the stars
	 * of the Files app now, so the ones that were set are handed over, and
	 * what those games were played for is kept.
	 */
	private function convertFavorites(IOutput $output): void {
		$favorites = $this->userConfig->getValuesByUsers(Application::APP_ID, 'favorites');
		$handed = 0;
		foreach ($favorites as $userId => $stored) {
			$userId = (string)$userId;
			$entries = is_string($stored) ? json_decode($stored, true) : null;
			$entries = is_array($entries) ? array_values($entries) : [];
			if ($entries !== []) {
				$tags = $this->tagManager->load('files', [], false, $userId);
				if ($tags === null) {
					// The blob stays, and the next upgrade tries again.
					continue;
				}
				foreach ($entries as $entry) {
					if (!is_array($entry)) {
						continue;
					}
					$path = (string)($entry['path'] ?? '');
					if ($path === '') {
						continue;
					}
					$id = Folders::fileId($this->rootFolder, $userId, $path);
					if ($id === null) {
						continue;
					}
					$tags->addToFavorites($id);
					$this->playMapper->importPlay(
						$userId,
						$id,
						(int)($entry['plays'] ?? 0),
						(int)($entry['seconds'] ?? 0),
						(int)($entry['time'] ?? 0),
					);
				}
			}
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'favorites');
			$handed++;
		}
		if ($handed > 0) {
			$output->info("Handed the favorites of $handed users to the Files app.");
		}
	}
}
