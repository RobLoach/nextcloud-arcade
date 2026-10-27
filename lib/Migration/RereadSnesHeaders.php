<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\BackgroundJob\RereadRoms;
use OCA\Arcade\CoreMap;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Has every Super Nintendo game read again that never got its title.
 *
 * The header of a Super Nintendo cartridge sits 32 KiB into the file, and
 * versions before 0.48.17 stopped reading at the first 8 KiB a file stream
 * of Nextcloud hands over -- so no SNES game got a title or a region. The
 * background pass only goes back to games it has never named a system
 * for, and every one of these has its system from its name, so nothing
 * would ever read them again.
 *
 * Done once: a game with no title after that is one whose cartridge has
 * none to give.
 *
 * @psalm-suppress UnusedClass
 */
class RereadSnesHeaders implements IRepairStep {
	private const DONE = 'snes_headers_reread';
	/** Files handed to each job. */
	private const BATCH = 100;

	public function __construct(
		private IDBConnection $connection,
		private IMimeTypeLoader $mimeTypeLoader,
		private IJobList $jobList,
		private IAppConfig $appConfig,
	) {
	}

	public function getName(): string {
		return 'Read Super Nintendo games again for their titles';
	}

	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(Application::APP_ID, self::DONE)) {
			return;
		}
		// Every one of them, unread: which have a title already is for the
		// jobs to ask, a batch at a time, rather than this a file at a
		// time in the middle of an upgrade.
		$ids = $this->snesFileIds();
		foreach (array_chunk($ids, self::BATCH) as $batch) {
			$this->jobList->add(RereadRoms::class, ['fileIds' => $batch]);
		}
		if ($ids !== []) {
			$output->info('Queued ' . count($ids) . ' Super Nintendo games to be read again for their titles, where they have none');
		}
		$this->appConfig->setValueBool(Application::APP_ID, self::DONE, true);
	}

	/**
	 * Every file of the instance with a Super Nintendo type, by the names
	 * this and earlier versions gave it. The type is indexed, where a
	 * search by extension would read the whole file cache.
	 *
	 * @return list<int>
	 */
	protected function snesFileIds(): array {
		$mimeIds = [];
		foreach (CoreMap::mimesOf('snes') as $mime) {
			if ($this->mimeTypeLoader->exists($mime)) {
				$mimeIds[] = $this->mimeTypeLoader->getId($mime);
			}
		}
		if ($mimeIds === []) {
			return [];
		}
		$qb = $this->connection->getTypedQueryBuilder();
		$qb->selectColumns('fileid')
			->from('filecache')
			->where($qb->expr()->in('mimetype', $qb->createNamedParameter($mimeIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['fileid'];
		}
		$result->closeCursor();
		return $ids;
	}
}
