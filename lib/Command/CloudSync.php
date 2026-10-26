<?php

declare(strict_types=1);

namespace OCA\Arcade\Command;

use OCA\Arcade\Service\CleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts the Cloud Sync manifests right: the saves that reached a saves
 * folder some other way than through the player are recorded, so
 * RetroArch fetches them, and the ones that are gone recorded as deleted.
 *
 * The weekly background job does the same for everybody; this is for not
 * waiting a week, and for --full, which hashes every file rather than
 * only the ones changed since the manifest was written.
 *
 * @psalm-suppress UnusedClass
 */
class CloudSync extends Command {
	public function __construct(
		private CleanupService $cleanupService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('arcade:cloudsync')
			->setDescription('Bring the RetroArch Cloud Sync manifests in line with the saves folders')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this user')
			->addOption('full', null, InputOption::VALUE_NONE, 'Hash every file, not only those changed since the manifest was written');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getOption('user');
		$counts = $this->cleanupService->reconcileManifests(
			is_string($user) && $user !== '' ? $user : null,
			(bool)$input->getOption('full'),
			static function (string $line) use ($output): void {
				$output->writeln($line);
			},
		);
		$output->writeln(
			"Recorded <info>{$counts['recorded']}</info> files and marked <info>{$counts['removed']}</info> as deleted, "
			. "for <info>{$counts['users']}</info> users",
		);
		return 0;
	}
}
