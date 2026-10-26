<?php

declare(strict_types=1);

namespace OCA\Arcade\Command;

use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Db\PlayMapper;
use OCA\Arcade\Service\Maintenance;
use OCA\Arcade\Service\StateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Says what the app holds, and for whom.
 *
 * Saves live in each user's own files now, where their quota counts them,
 * so what is reported is what no file listing shows: the games each user
 * has saves for, what they played and for how long, and whatever earlier
 * versions left in the app data, which nothing reads any more.
 *
 * @psalm-suppress UnusedClass
 */
class Status extends Command {
	public function __construct(
		private StateService $stateService,
		private GameMapper $gameMapper,
		private PlayMapper $playMapper,
		private Maintenance $maintenance,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('arcade:status')
			->setDescription('Report the save state storage and play activity of every user')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON instead');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Everything below reads the tables of the app, and a missing one
		// is a database error a page long that says nothing of what to do.
		$problems = $this->maintenance->databaseProblems();
		if ($problems !== []) {
			foreach ($problems as $problem) {
				$output->writeln("<error>$problem</error>");
			}
			$output->writeln('Run `occ arcade:repair`, or Repair the database under Administration settings → Arcade → Maintenance.');
			return 1;
		}
		$games = $this->gameMapper->countsByUser();
		$plays = $this->playMapper->totalsByUser();
		$userIds = array_unique(array_map(strval(...), [...array_keys($games), ...array_keys($plays)]));
		sort($userIds);

		$users = [];
		$totals = ['games' => 0, 'plays' => 0, 'seconds' => 0];
		foreach ($userIds as $userId) {
			$user = [
				'user' => $userId,
				'games' => $games[$userId] ?? 0,
				'plays' => $plays[$userId]['plays'] ?? 0,
				'seconds' => $plays[$userId]['seconds'] ?? 0,
			];
			$users[] = $user;
			$totals['games'] += $user['games'];
			$totals['plays'] += $user['plays'];
			$totals['seconds'] += $user['seconds'];
		}

		$status = [
			'users' => $users,
			'totals' => $totals,
			'appDataSaves' => $this->stateService->appDataSaves(),
			'jobs' => $this->maintenance->jobs(),
		];

		if ((bool)$input->getOption('json')) {
			$json = json_encode($status, JSON_PRETTY_PRINT);
			$output->writeln($json === false ? '{}' : $json);
			return 0;
		}
		$this->report($status, $output);
		return 0;
	}

	/**
	 * @param array{
	 *     users: list<array{user: string, games: int, plays: int, seconds: int}>,
	 *     totals: array{games: int, plays: int, seconds: int},
	 *     appDataSaves: array{files: int, bytes: int},
	 *     jobs: array<string, array{running: bool, last: ?array{status: string, at: int, seconds: ?float}}>,
	 * } $status
	 */
	private function report(array $status, OutputInterface $output): void {
		if ($status['users'] === []) {
			$output->writeln('Nothing is stored: nobody has saved or played anything yet.');
		} else {
			$output->writeln(sprintf('  %-24s %7s %7s %10s', 'User', 'Games', 'Plays', 'Playtime'));
			foreach ($status['users'] as $user) {
				$output->writeln(sprintf(
					'  %-24s %7d %7d %10s',
					$user['user'],
					$user['games'],
					$user['plays'],
					$this->playtimeOf($user['seconds']),
				));
			}
			$totals = $status['totals'];
			$output->writeln(sprintf(
				'  %-24s %7d %7d %10s',
				'Total',
				$totals['games'],
				$totals['plays'],
				$this->playtimeOf($totals['seconds']),
			));
		}

		$this->reportJobs($status['jobs'], $output);

		$left = $status['appDataSaves'];
		if ($left['files'] > 0) {
			$output->writeln(sprintf(
				'<comment>%d save files (%s) earlier versions left in the app data are no longer read. '
				. '`occ arcade:cleanup --legacy` removes them.</comment>',
				$left['files'],
				$this->sizeOf($left['bytes']),
			));
		}
	}

	/**
	 * How each background job last went: a failure otherwise only shows
	 * in the log.
	 *
	 * @param array<string, array{running: bool, last: ?array{status: string, at: int, seconds: ?float}}> $jobs
	 */
	private function reportJobs(array $jobs, OutputInterface $output): void {
		if ($jobs === []) {
			return;
		}
		$names = [
			'scan' => 'Scanning every library',
			'cleanup' => 'Weekly cleanup',
			'thumbnails' => 'Fetching thumbnails',
			'metadata' => 'Reading game details',
		];
		$output->writeln('');
		$output->writeln('Background jobs:');
		foreach ($jobs as $job => $state) {
			$last = $state['last'];
			if ($last === null) {
				$said = 'has not run yet';
			} else {
				$when = date('Y-m-d H:i', $last['at']);
				$took = $last['seconds'] === null ? '' : sprintf(', took %s', $this->durationOf($last['seconds']));
				$said = match ($last['status']) {
					'failed' => "<error>failed</error> at $when$took",
					'crashed' => "<error>crashed</error> at $when$took",
					default => "finished at $when$took",
				};
			}
			if ($state['running']) {
				$said = $last === null ? 'running now' : "running now; last $said";
			}
			$output->writeln(sprintf('  %-24s %s', $names[$job] ?? $job, $said));
		}
	}

	private function durationOf(float $seconds): string {
		if ($seconds < 1) {
			return '<1s';
		}
		if ($seconds < 60) {
			return sprintf('%.1fs', $seconds);
		}
		return $this->playtimeOf((int)round($seconds));
	}

	private function sizeOf(int $bytes): string {
		$units = ['KB', 'MB', 'GB', 'TB'];
		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		$value = (float)$bytes;
		$unit = '';
		foreach ($units as $unit) {
			$value /= 1024.0;
			if ($value < 1024.0) {
				break;
			}
		}
		return sprintf('%.1f %s', $value, $unit);
	}

	private function playtimeOf(int $seconds): string {
		if ($seconds < 60) {
			return $seconds . 's';
		}
		$minutes = intdiv($seconds, 60);
		$hours = intdiv($minutes, 60);
		$minutes %= 60;
		return $hours === 0 ? sprintf('%dm', $minutes) : sprintf('%dh %02dm', $hours, $minutes);
	}
}
