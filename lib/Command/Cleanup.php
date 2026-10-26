<?php

declare(strict_types=1);

namespace OCA\Arcade\Command;

use OCA\Arcade\Service\CleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes save states of games and users that no longer exist.
 *
 * The sweep itself lives in CleanupService, where the weekly background
 * job runs the same one; this command only parses the options, runs it
 * and prints what happened.
 *
 * @psalm-suppress UnusedClass
 */
class Cleanup extends Command {
	public function __construct(
		private CleanupService $cleanupService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('arcade:cleanup')
			->setDescription('Remove save states of games and users that no longer exist')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be removed')
			->addOption('legacy', null, InputOption::VALUE_NONE, 'Also remove the saves versions before 0.48.8 left in the app data, which are no longer read');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		if ($dryRun) {
			$output->writeln('<comment>Dry run, nothing is removed.</comment>');
		}

		$legacy = (bool)$input->getOption('legacy');
		$counts = $this->cleanupService->sweep(
			$dryRun,
			static function (string $line) use ($output): void {
				$output->writeln($line);
			},
			$legacy,
		);

		$output->writeln(
			"Removed the states of <info>{$counts['users']}</info> users and <info>{$counts['games']}</info> games",
		);
		if ($counts['appDataRemoved'] > 0) {
			$output->writeln("Removed <info>{$counts['appDataRemoved']}</info> save files earlier versions left in the app data");
		}
		$left = $counts['appData']['files'];
		if ($left > 0 && !($legacy && !$dryRun)) {
			$output->writeln(
				"<comment>$left save files earlier versions left in the app data are no longer read"
				. ($legacy ? '.' : '. Run with --legacy to remove them.') . '</comment>',
			);
		}
		return 0;
	}
}
