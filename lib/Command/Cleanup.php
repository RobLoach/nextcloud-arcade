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
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be removed');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		if ($dryRun) {
			$output->writeln('<comment>Dry run, nothing is removed.</comment>');
		}

		$counts = $this->cleanupService->sweep(
			$dryRun,
			static function (string $line) use ($output): void {
				$output->writeln($line);
			},
		);

		$output->writeln(
			"Removed the states of <info>{$counts['users']}</info> users and <info>{$counts['games']}</info> games",
		);
		if ($counts['legacy'] > 0) {
			$output->writeln(
				"<comment>{$counts['legacy']} files written before the states were kept per user are left. "
				. 'They hold no record of whose they are, so they are only counted, never touched.</comment>',
			);
		}
		return 0;
	}
}
