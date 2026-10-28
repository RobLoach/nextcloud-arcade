<?php

declare(strict_types=1);

namespace OCA\Arcade\Command;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Migration\UninstallCleanup;
use OCP\Config\IUserConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Removes everything the app has written outside of the user's own files.
 *
 * Removing an app leaves its rows behind: Nextcloud deletes the code and
 * nothing else. Disabling the app cannot be the moment to clear them either,
 * since a server upgrade disables apps that have not caught up yet, and
 * coming back to a wiped library would be a poor welcome. So it is asked for
 * here, once, before the app goes.
 *
 * @psalm-suppress UnusedClass
 */
class Uninstall extends Command {
	/** The tables of the app, which removing the app leaves behind. */
	private const TABLES = ['arcade_plays', 'arcade_games'];

	public function __construct(
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private IAppDataFactory $appDataFactory,
		private UninstallCleanup $cleanup,
		private IMimeTypeLoader $mimeTypeLoader,
		private IDBConnection $connection,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('arcade:uninstall')
			->setDescription('Remove the settings, save states and mimetypes of the app, before removing the app itself')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be removed')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask for confirmation');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		if ($dryRun) {
			$output->writeln('<comment>Dry run, nothing is removed.</comment>');
		} elseif (!$input->getOption('force') && !$this->confirmed($input, $output)) {
			$output->writeln('Nothing was removed.');
			return 0;
		}

		$this->forgetMimeTypes($dryRun, $output);
		$this->forgetSaveStates($dryRun, $output);
		$this->forgetTables($dryRun, $output);
		$this->forgetWork($dryRun, $output);
		$this->forgetSettings($dryRun, $output);

		$output->writeln('');
		$output->writeln(
			'Games, saves, screenshots and thumbnails in the folders of a user are their own files, and are left alone.',
		);
		if (!$dryRun) {
			$output->writeln('Now remove the app itself with <info>occ app:remove arcade</info>.');
		}
		return 0;
	}

	private function confirmed(InputInterface $input, OutputInterface $output): bool {
		if (!$input->isInteractive()) {
			$output->writeln('<error>Run this with --force to remove without being asked, or with --dry-run first.</error>');
			return false;
		}
		$helper = $this->getHelper('question');
		if (!$helper instanceof QuestionHelper) {
			return false;
		}

		$output->writeln('This removes the settings of every user, what they played, and every save state kept by the app.');
		return (bool)$helper->ask($input, $output, new ConfirmationQuestion('Remove all of it? [y/N] ', false));
	}

	/**
	 * ROMs were given their own mimetype so the viewer would offer to play
	 * them. With the app gone, nothing reads those any more, so the files go
	 * back to what Nextcloud makes of them on its own.
	 */
	private function forgetMimeTypes(bool $dryRun, OutputInterface $output): void {
		$extensions = array_keys(CoreMap::extensionMimeMap());
		if ($dryRun) {
			$output->writeln(
				'Would put the files of ' . count($extensions) . ' ROM extensions back to application/octet-stream',
			);
			return;
		}

		$octetStream = $this->mimeTypeLoader->getId('application/octet-stream');
		$files = 0;
		foreach ($extensions as $extension) {
			$files += $this->mimeTypeLoader->updateFilecache($extension, $octetStream);
		}
		$output->writeln("Put <info>$files</info> ROMs back to application/octet-stream");
	}

	private function forgetSaveStates(bool $dryRun, OutputInterface $output): void {
		try {
			$listing = $this->appDataFactory->get(Application::APP_ID)->getDirectoryListing();
		} catch (\Throwable) {
			$output->writeln('No save states were kept');
			return;
		}

		$removed = 0;
		foreach ($listing as $node) {
			if (!$dryRun) {
				$node->delete();
			}
			$removed++;
		}
		$verb = $dryRun ? 'Would remove' : 'Removed';
		$output->writeln("$verb <info>$removed</info> folders of save states and battery saves");
	}

	/**
	 * The play records and the registry of the games with saves, which live
	 * in tables of the app. Removing the app deletes the code and nothing
	 * else, so the tables go here.
	 */
	private function forgetTables(bool $dryRun, OutputInterface $output): void {
		$dropped = [];
		foreach (self::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				continue;
			}
			if (!$dryRun) {
				$this->connection->dropTable($table);
			}
			$dropped[] = $table;
		}
		// Nextcloud runs a migration only once, and keeps the record of it
		// past the app's removal: with the record left, installing the app
		// again would find its migrations done and make no tables at all.
		if (!$dryRun) {
			$this->connection->executeStatement(
				'DELETE FROM `*PREFIX*migrations` WHERE `app` = ?',
				[Application::APP_ID],
			);
		}
		$verb = $dryRun ? 'Would drop' : 'Dropped';
		$output->writeln(
			$dropped === []
				? 'No tables of the app were found'
				: "$verb the tables of the app: <info>" . implode(', ', $dropped) . '</info>',
		);
	}

	private function forgetWork(bool $dryRun, OutputInterface $output): void {
		if (!$dryRun) {
			$this->cleanup->dropTransientWork();
		}
		$verb = $dryRun ? 'Would drop' : 'Dropped';
		$output->writeln("$verb the queued box art lookups and the cached listings");
	}

	/**
	 * The personal settings, the recently played, the favorites and the
	 * instance-wide core options, in that order: the app config holds what
	 * Nextcloud itself files under the app, so it goes last.
	 */
	private function forgetSettings(bool $dryRun, OutputInterface $output): void {
		if (!$dryRun) {
			$this->userConfig->deleteApp(Application::APP_ID);
			$this->appConfig->deleteApp(Application::APP_ID);
		}
		$verb = $dryRun ? 'Would remove' : 'Removed';
		$output->writeln("$verb the settings of every user and the settings of the instance");
	}
}
