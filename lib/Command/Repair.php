<?php

declare(strict_types=1);

namespace OCA\Arcade\Command;

use OCA\Arcade\Service\Maintenance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts the database tables of the app right, as the maintenance section of
 * the administration settings does: whatever the migrations would have made
 * and is missing is made, and nothing that is there is touched.
 *
 * @psalm-suppress UnusedClass
 */
class Repair extends Command {
	public function __construct(
		private Maintenance $maintenance,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('arcade:repair')
			->setDescription('Make the database tables and columns of Arcade that are missing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$before = $this->maintenance->databaseProblems();
		if ($before === []) {
			$output->writeln('The database tables of Arcade are in order.');
			return 0;
		}
		foreach ($before as $problem) {
			$output->writeln("<comment>$problem</comment>");
		}
		$after = $this->maintenance->repairDatabase();
		if ($after !== []) {
			foreach ($after as $problem) {
				$output->writeln("<error>Still: $problem</error>");
			}
			return 1;
		}
		$output->writeln('<info>Repaired: the database tables of Arcade are in order.</info>');
		return 0;
	}
}
