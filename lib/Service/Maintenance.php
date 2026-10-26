<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Migration\RegisterMimeTypes;
use OCP\App\IAppManager;
use OCP\DB\Exception;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * The maintenance tasks of the administration settings, and of the occ
 * commands that do the same: putting the database right, giving ROMs their
 * file types, and clearing away what nothing needs any more.
 */
class Maintenance {
	/**
	 * Every table the app keeps, with the columns added to it since it was
	 * first made: a column a later migration adds is the one an upgrade
	 * that went wrong leaves out.
	 */
	private const TABLES = [
		'arcade_plays' => [],
		'arcade_games' => ['core', 'name'],
	];

	public function __construct(
		private IDBConnection $connection,
		private IAppManager $appManager,
		private IConfig $config,
		private CleanupService $cleanupService,
		private StateService $stateService,
		private RegisterMimeTypes $mimeTypes,
		private JobHistory $jobHistory,
	) {
	}

	/**
	 * What is wrong with the tables of the app, in words for whoever has to
	 * put it right; none when nothing is.
	 *
	 * @return list<string>
	 */
	public function databaseProblems(): array {
		$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
		$problems = [];
		foreach (self::TABLES as $table => $columns) {
			if (!$this->connection->tableExists($table)) {
				$problems[] = "The table $prefix$table is missing";
				continue;
			}
			foreach ($columns as $column) {
				if (!$this->hasColumn($table, $column)) {
					$problems[] = "The column $column of $prefix$table is missing";
				}
			}
		}
		return $problems;
	}

	/**
	 * Put the tables of the app right, and say what is still wrong after.
	 *
	 * Nextcloud runs a migration only once, so one recorded as run whose
	 * table is gone -- dropped, or lost to an upgrade that failed half way
	 * -- would never be run again. The record of what ran is forgotten and
	 * the migrations run anew instead; each of them makes only what is not
	 * there yet, so on a database that is in order this changes nothing.
	 *
	 * @return list<string>
	 */
	public function repairDatabase(): array {
		$qb = $this->connection->getTypedQueryBuilder();
		$qb->delete('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)));
		$qb->executeStatement();
		$this->appManager->upgradeApp(Application::APP_ID);
		return $this->databaseProblems();
	}

	/**
	 * Give every ROM the file cache knows its file type, for the ones that
	 * arrived before the app was enabled.
	 *
	 * @return array{updated: int, healed: int}
	 */
	public function fixFileTypes(): array {
		return $this->mimeTypes->register();
	}

	/**
	 * What the maintenance section shows before anything is pressed: only
	 * what is cheap to find. What is left behind of deleted games and users
	 * takes a walk over every user's saves, so it waits to be asked for.
	 *
	 * @return array{
	 *     database: list<string>,
	 *     appData: array{files: int, bytes: int},
	 *     jobs: array<string, array{running: bool, last: ?array{status: string, at: int, seconds: ?float}}>,
	 * }
	 */
	public function status(): array {
		return [
			'database' => $this->databaseProblems(),
			'appData' => $this->stateService->appDataSaves(),
			'jobs' => $this->jobs(),
		];
	}

	/**
	 * How the background jobs of the app last went.
	 *
	 * @return array<string, array{running: bool, last: ?array{status: string, at: int, seconds: ?float}}>
	 */
	public function jobs(): array {
		return $this->jobHistory->states();
	}

	/**
	 * How many deleted games and users have saves left behind, found the
	 * way removing them would, without removing anything.
	 *
	 * @return array{users: int, games: int}
	 */
	public function findLeftBehind(): array {
		$counts = $this->cleanupService->sweep(true);
		return ['users' => $counts['users'], 'games' => $counts['games']];
	}

	/**
	 * Remove the saves of games and users that no longer exist.
	 *
	 * @return array{users: int, games: int}
	 */
	public function removeLeftBehind(): array {
		$counts = $this->cleanupService->sweep(false);
		return ['users' => $counts['users'], 'games' => $counts['games']];
	}

	/** Remove what earlier versions left in the app data; says how many files went. */
	public function removeAppDataSaves(): int {
		return $this->stateService->deleteAppDataSaves();
	}

	/**
	 * Whether a table has a column. Asked of the table itself, which every
	 * database answers the same way: a query naming a column that is not
	 * there fails.
	 */
	private function hasColumn(string $table, string $column): bool {
		try {
			$qb = $this->connection->getTypedQueryBuilder();
			$qb->selectColumns($column)->from($table)->setMaxResults(1);
			$qb->executeQuery()->closeCursor();
			return true;
		} catch (Exception) {
			return false;
		}
	}
}
