<?php

declare(strict_types=1);

namespace OCA\Arcade\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The play records, one row per user and game.
 *
 * The counting happens in the database itself -- plays = plays + 1,
 * seconds = seconds + n -- so two sessions ending at the same moment both
 * count, where a read-modify-write of a JSON blob would keep only one.
 *
 * @template-extends UniqueRowMapper<Play>
 */
class PlayMapper extends UniqueRowMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'arcade_plays', Play::class);
	}

	/**
	 * The games played last, newest first, as file ids. Only games whose
	 * start was seen: a row holding nothing but reported play time is not
	 * a game that was played here last.
	 *
	 * @return list<int>
	 */
	public function recentFileIds(string $userId, int $limit): array {
		$qb = $this->db->getTypedQueryBuilder();
		$qb->selectColumns('file_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gt('plays', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('last_played', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['file_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * What each game was played for, by the id of its file.
	 *
	 * @return array<int, array{seconds: int, plays: int, time: int}>
	 */
	public function statsOf(string $userId): array {
		$qb = $this->db->getTypedQueryBuilder();
		$qb->selectColumns('file_id', 'plays', 'seconds', 'last_played')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$stats = [];
		while (($row = $result->fetch()) !== false) {
			$stats[(int)$row['file_id']] = [
				'seconds' => (int)$row['seconds'],
				'plays' => (int)$row['plays'],
				'time' => (int)$row['last_played'],
			];
		}
		$result->closeCursor();
		return $stats;
	}

	/**
	 * What every user played, all their games added up, for the status
	 * report.
	 *
	 * @return array<string, array{plays: int, seconds: int}> by user id
	 */
	public function totalsByUser(): array {
		$qb = $this->db->getTypedQueryBuilder();
		// One call each: what a row holds is read off $qb itself, which a
		// chain does not tell Psalm about past its first link. Every select
		// after the first trips the `S of never` bound the builder is
		// declared with -- core's own Psalm test of it does the same -- so
		// that one issue is let through here; the columns still add up.
		$qb->selectColumns('user_id');
		/** @psalm-suppress InvalidTemplateParam */
		$qb->selectAlias($qb->func()->sum('plays'), 'plays');
		/** @psalm-suppress InvalidTemplateParam */
		$qb->selectAlias($qb->func()->sum('seconds'), 'seconds');
		/** @psalm-suppress InvalidTemplateParam */
		$qb->from($this->getTableName())
			->groupBy('user_id');
		$result = $qb->executeQuery();
		$totals = [];
		while (($row = $result->fetch()) !== false) {
			$totals[(string)$row['user_id']] = [
				'plays' => (int)$row['plays'],
				'seconds' => (int)$row['seconds'],
			];
		}
		$result->closeCursor();
		return $totals;
	}

	/**
	 * A game was started: one more play, and it moves to the front.
	 */
	public function recordPlay(string $userId, int $fileId, int $time): void {
		$play = new Play();
		$play->setUserId($userId);
		$play->setFileId($fileId);
		$play->setPlays(1);
		$play->setSeconds(0);
		$play->setLastPlayed($time);
		$this->updateOrInsert(fn (): int => $this->countPlay($userId, $fileId, $time), $play);
	}

	private function countPlay(string $userId, int $fileId, int $time): int {
		$qb = $this->db->getTypedQueryBuilder();
		// Played again within the same second, the game still moves in
		// front of what was played since: one past its old moment, when
		// the clock alone would leave it where it was.
		$moved = $qb->func()->greatest(
			$qb->func()->add('last_played', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
			$qb->createNamedParameter($time, IQueryBuilder::PARAM_INT),
		);
		$qb->update($this->getTableName())
			->set('plays', $qb->func()->add('plays', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->set('last_played', $moved)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Add the length of a session to what a game was played for. The moment
	 * it was last played stays as it is: reporting time on leaving a game is
	 * not playing it again.
	 */
	public function addSeconds(string $userId, int $fileId, int $seconds, int $time): void {
		// A game whose start was never recorded still counts.
		$play = new Play();
		$play->setUserId($userId);
		$play->setFileId($fileId);
		$play->setPlays(0);
		$play->setSeconds($seconds);
		$play->setLastPlayed($time);
		$this->updateOrInsert(fn (): int => $this->countSeconds($userId, $fileId, $seconds), $play);
	}

	private function countSeconds(string $userId, int $fileId, int $seconds): int {
		$qb = $this->db->getTypedQueryBuilder();
		$qb->update($this->getTableName())
			->set('seconds', $qb->func()->add('seconds', $qb->createNamedParameter($seconds, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Drop everything counted for a user, for when the user is deleted.
	 * The user config used to go with them by itself; a table of ours is
	 * ours to clear.
	 */
	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getTypedQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Drop what was counted of games that are gone, by the ids of their
	 * files.
	 */
	public function removeFiles(string $userId, int ...$fileIds): void {
		if ($fileIds === []) {
			return;
		}
		$qb = $this->db->getTypedQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}

	/**
	 * Bring over what an old JSON blob counted. A row that is already there
	 * has counted on since, and wins.
	 */
	public function importPlay(string $userId, int $fileId, int $plays, int $seconds, int $time): void {
		$play = new Play();
		$play->setUserId($userId);
		$play->setFileId($fileId);
		$play->setPlays($plays);
		$play->setSeconds($seconds);
		$play->setLastPlayed($time);
		$this->insertUnlessThere($play);
	}
}
