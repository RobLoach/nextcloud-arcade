<?php

declare(strict_types=1);

namespace OCA\Arcade\SetupChecks;

use OCA\Arcade\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether the server runs background jobs the way the app needs them run.
 *
 * A game says what it is -- system, title, checksum -- only when a
 * background job reads it. A library that was there before the app, or one
 * whose server never runs its jobs, is never read, and the support question
 * that follows always looks like a bug in the app. This check points at the
 * server instead, from the admin overview where such questions start.
 */
class ArcadeSetupCheck implements ISetupCheck {
	/**
	 * Cron is expected every five minutes; an hour of silence means it is
	 * not running at all, not that it is merely slow.
	 */
	public const MAX_CRON_AGE = 3600;

	public const DOC_LINK
		= 'https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html';

	public function __construct(
		private IL10N $l,
		private IAppConfig $appConfig,
		private IJobList $jobList,
		private ITimeFactory $timeFactory,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l->t('Arcade background jobs');
	}

	public function run(): SetupResult {
		$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		if ($mode === 'ajax') {
			return SetupResult::warning(
				$this->l->t('Background jobs are set to AJAX, which only runs while someone has the web interface open. Arcade reads what a game says about itself - system, title, checksum - in background jobs, so games may stay unrecognized. Switch to cron.')
				. $this->waiting(),
				self::DOC_LINK,
			);
		}

		$lastCron = $this->appConfig->getValueInt('core', 'lastcron', 0);
		if ($lastCron === 0) {
			return SetupResult::warning(
				$this->l->t('Background jobs have never run. Arcade reads what a game says about itself - system, title, checksum - in background jobs, so games stay unrecognized until they run.')
				. $this->waiting(),
				self::DOC_LINK,
			);
		}
		if ($this->timeFactory->getTime() - $lastCron > self::MAX_CRON_AGE) {
			return SetupResult::warning(
				$this->l->t('The last background job ran more than an hour ago. Arcade reads what a game says about itself in background jobs, so games stay unrecognized until they run again.')
				. $this->waiting(),
				self::DOC_LINK,
			);
		}

		if ($this->hasQueuedJobs()) {
			return SetupResult::info(
				$this->l->t('Background jobs are running, and Arcade has work queued that will be handled by the next runs.'),
			);
		}
		return SetupResult::success(
			$this->l->t('Background jobs are running, and Arcade has no work waiting.'),
		);
	}

	/**
	 * A sentence for the warnings: work already queued makes the stalled
	 * queue a fact rather than a possibility.
	 */
	private function waiting(): string {
		if (!$this->hasQueuedJobs()) {
			return '';
		}
		return ' ' . $this->l->t('Arcade has jobs waiting in the queue right now.');
	}

	private function hasQueuedJobs(): bool {
		foreach (Application::JOBS as $class) {
			// A recurring job sits in the list by design, so its being
			// there says nothing about work waiting; only the queued ones
			// speak of that.
			if (is_subclass_of($class, TimedJob::class)) {
				continue;
			}
			foreach ($this->jobList->getJobsIterator($class, 1, 0) as $job) {
				return true;
			}
		}
		return false;
	}
}
