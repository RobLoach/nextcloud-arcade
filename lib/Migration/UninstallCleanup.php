<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Service\Caches;
use OCP\BackgroundJob\IJobList;
use OCP\ICacheFactory;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Runs when the app is disabled, which is also the first thing removing it
 * does.
 *
 * Nextcloud gives an app no way to tell those two apart, and it disables apps
 * by itself when a server upgrade leaves them behind, so this drops only what
 * is cheap to build again: queued work and caches. Anything a user would miss
 * -- settings, recently played, save states -- is left where it is, and goes
 * only when asked for with `occ arcade:uninstall`.
 *
 * @psalm-suppress UnusedClass
 */
class UninstallCleanup implements IRepairStep {
	public function __construct(
		private IJobList $jobList,
		private ICacheFactory $cacheFactory,
	) {
	}

	public function getName(): string {
		return 'Drop queued Arcade work and its caches';
	}

	public function run(IOutput $output): void {
		$this->dropTransientWork();
		$output->info(
			'Queued box art lookups and cached listings are gone. Settings, '
			. 'recently played and save states are kept: `occ arcade:uninstall` removes those.',
		);
	}

	/**
	 * The queued work and the caches, which are the app's to drop whenever
	 * it stops: cron would drop the jobs by itself once the class cannot be
	 * loaded, with a warning in the log on the way. This is the quiet way.
	 */
	public function dropTransientWork(): void {
		foreach (Application::JOBS as $job) {
			$this->jobList->remove($job);
		}
		foreach (Application::CACHES as $cache) {
			// The same cache the app wrote to: distributed when the instance
			// has one, local when only that is configured.
			Caches::create($this->cacheFactory, Application::APP_ID . $cache)->clear();
		}
	}
}
