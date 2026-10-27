<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use OCA\Arcade\BackgroundJob\ConvertSavesLayout;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Has the saves of versions up to 0.48.7 moved to the layout RetroArch
 * keeps -- not here, but by a background job, and before that by the first
 * request that asks after a user's saves (see SavesLayoutConverter). The
 * upgrade runs without the apps that keep a file's versions and its trash,
 * and every file moved in it would log an error for them.
 *
 * @psalm-suppress UnusedClass
 */
class ConvertSavesFolderLayout implements IRepairStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	public function getName(): string {
		return 'Have the saves of earlier versions moved to the layout RetroArch keeps';
	}

	public function run(IOutput $output): void {
		if (!$this->jobList->has(ConvertSavesLayout::class, null)) {
			$this->jobList->add(ConvertSavesLayout::class, null);
		}
	}
}
