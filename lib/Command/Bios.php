<?php

declare(strict_types=1);

namespace OCA\Arcade\Command;

use OCA\Arcade\CoreMap;
use OCA\Arcade\Service\BiosService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts a BIOS file where every player on the instance can reach it.
 *
 * The file a console needs is the one thing a player cannot make for
 * themselves, and asking every user to find their own copy is asking a lot.
 * An administrator puts it here once instead.
 *
 * @psalm-suppress UnusedClass
 */
class Bios extends Command {
	public function __construct(
		private BiosService $biosService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('arcade:bios')
			->setDescription('List, add or remove the BIOS files the instance offers to every player')
			->addArgument('file', InputArgument::OPTIONAL, 'The BIOS file to add, by path on this machine')
			->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Remove the file of this name instead');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$remove = $input->getOption('remove');
		if (is_string($remove) && $remove !== '') {
			$output->writeln($this->biosService->remove($remove)
				? "Removed <info>$remove</info>"
				: "<comment>$remove was not there</comment>");
			return 0;
		}

		$file = $input->getArgument('file');
		if (!is_string($file) || $file === '') {
			$this->listBios($output);
			return 0;
		}
		if (!is_readable($file)) {
			$output->writeln("<error>$file cannot be read</error>");
			return 1;
		}

		$name = basename($file);
		// Under the spelling the cores are listed by, which is what the
		// store keeps and so what the player will go looking for -- the
		// file on disk may be called anything.
		$canonical = BiosService::canonicalName($name);
		if ($canonical === null) {
			$output->writeln("<error>No core asks for a file called $name.</error>");
			$output->writeln('The names that are asked for:');
			$this->listBios($output);
			return 1;
		}
		$this->biosService->write($canonical, (string)file_get_contents($file));
		$output->writeln("Added <info>$canonical</info>, which every player can now use.");
		return 0;
	}

	/**
	 * Every file that may be asked for, the system asking, and whether the
	 * instance has it.
	 */
	private function listBios(OutputInterface $output): void {
		$held = $this->biosService->held();
		foreach (CoreMap::SYSTEMS as $system) {
			foreach ($system['bios'] as $name) {
				$mark = in_array($name, $held, true) ? '<info>held</info>' : '<comment>missing</comment>';
				$output->writeln(sprintf('  %-24s %-18s %s', $name, $system['display'] ?? $system['short'], $mark));
			}
		}
	}
}
