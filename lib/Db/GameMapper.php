<?php

declare(strict_types=1);

namespace OCA\Arcade\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The games a user has states for, which used to be a games.json next to
 * the states. That is what tells apart a state whose game is gone from one
 * whose game is merely not being played.
 *
 * @template-extends QBMapper<Game>
 */
class GameMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'arcade_games', Game::class);
	}

	/**
	 * Every game of a user, by the key its states are filed under.
	 *
	 * @return array<string, array{path: string, md5: string, core: string}>
	 */
	public function entriesOf(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('game_key', 'last_path', 'checksum', 'core')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$entries = [];
		while (($row = $result->fetch()) !== false) {
			$entries[(string)$row['game_key']] = [
				'path' => (string)$row['last_path'],
				'md5' => (string)$row['checksum'],
				'core' => (string)$row['core'],
			];
		}
		$result->closeCursor();
		return $entries;
	}

	/**
	 * @return array{path: string, md5: string, core: string}|null
	 */
	public function entryOf(string $userId, string $key): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('last_path', 'checksum', 'core')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('game_key', $qb->createNamedParameter($key)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return [
			'path' => (string)$row['last_path'],
			'md5' => (string)$row['checksum'],
			'core' => (string)$row['core'],
		];
	}

	/**
	 * How many games each user has saves for, for the status report.
	 *
	 * @return array<string, int> user id => games
	 */
	public function countsByUser(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->selectAlias($qb->func()->count('game_key'), 'games')
			->from($this->getTableName())
			->groupBy('user_id');
		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$counts[(string)$row['user_id']] = (int)$row['games'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * Note where a game is now, what it hashes to, and the core the player
	 * ran it on, replacing what was noted before. An empty core leaves the
	 * one noted before alone: only the player knows it, and a write that
	 * came from anywhere else has nothing to say about it.
	 */
	public function set(string $userId, string $key, int $fileId, string $path, string $checksum, string $core = ''): void {
		if ($this->replace($userId, $key, $fileId, $path, $checksum, $core) > 0) {
			return;
		}
		$game = new Game();
		$game->setUserId($userId);
		$game->setGameKey($key);
		$game->setFileId($fileId);
		$game->setLastPath($path);
		$game->setChecksum($checksum);
		$game->setCore($core === '' ? null : $core);
		try {
			$this->insert($game);
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Another request noted it first. An update that changes nothing
			// counts no rows on some databases, so this one may too; either
			// way the entry is there.
			$this->replace($userId, $key, $fileId, $path, $checksum, $core);
		}
	}

	private function replace(string $userId, string $key, int $fileId, string $path, string $checksum, string $core): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('last_path', $qb->createNamedParameter($path))
			->set('checksum', $qb->createNamedParameter($checksum));
		if ($core !== '') {
			$qb->set('core', $qb->createNamedParameter($core));
		}
		$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('game_key', $qb->createNamedParameter($key)));
		return $qb->executeStatement();
	}

	/**
	 * Bring over what a games.json noted. An entry that is already there
	 * has been written since, and wins.
	 */
	public function importEntry(string $userId, string $key, int $fileId, string $path, string $checksum): void {
		$game = new Game();
		$game->setUserId($userId);
		$game->setGameKey($key);
		$game->setFileId($fileId);
		$game->setLastPath($path);
		$game->setChecksum($checksum);
		try {
			$this->insert($game);
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * Drop what is remembered of a game, by the names it is filed under.
	 */
	public function remove(string $userId, string ...$keys): void {
		if ($keys === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('game_key', $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
