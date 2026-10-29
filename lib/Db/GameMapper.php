<?php

declare(strict_types=1);

namespace OCA\Arcade\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The games a user has states for, which used to be a games.json next to
 * the states. That is what tells apart a state whose game is gone from one
 * whose game is merely not being played.
 *
 * @template-extends UniqueRowMapper<Game>
 */
class GameMapper extends UniqueRowMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'arcade_games', Game::class);
	}

	/**
	 * Every game of a user, by the key its states are filed under.
	 *
	 * @return array<string, array{path: string, md5: string, core: string, name: string}>
	 */
	public function entriesOf(string $userId): array {
		$qb = $this->db->getTypedQueryBuilder();
		$qb->selectColumns('game_key', 'last_path', 'checksum', 'core', 'name')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$entries = [];
		while (($row = $result->fetch()) !== false) {
			$entries[(string)$row['game_key']] = [
				'path' => (string)$row['last_path'],
				'md5' => (string)$row['checksum'],
				'core' => (string)$row['core'],
				'name' => (string)$row['name'],
			];
		}
		$result->closeCursor();
		return $entries;
	}

	/**
	 * @return array{path: string, md5: string, core: string, name: string}|null
	 */
	public function entryOf(string $userId, string $key): ?array {
		$qb = $this->db->getTypedQueryBuilder();
		$qb->selectColumns('last_path', 'checksum', 'core', 'name')
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
			'name' => (string)$row['name'],
		];
	}

	/**
	 * How many games each user has saves for, for the status report.
	 *
	 * @return array<string, int> user id => games
	 */
	public function countsByUser(): array {
		$qb = $this->db->getTypedQueryBuilder();
		// One call each: what a row holds is read off $qb itself, which a
		// chain does not tell Psalm about past its first link. Every select
		// after the first trips the `S of never` bound the builder is
		// declared with -- core's own Psalm test of it does the same -- so
		// that one issue is let through here; the columns still add up.
		$qb->selectColumns('user_id');
		/** @psalm-suppress InvalidTemplateParam */
		$qb->selectAlias($qb->func()->count('game_key'), 'games');
		/** @psalm-suppress InvalidTemplateParam */
		$qb->from($this->getTableName())
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
	 * Note where a game is now, what it hashes to, the core the player ran
	 * it on and the name its saves go by, replacing what was noted before.
	 * An empty core, or a null name, leaves the one noted before alone:
	 * only the player knows them, and a write that came from anywhere else
	 * has nothing to say about them. An empty name clears the one noted.
	 */
	public function set(string $userId, string $key, int $fileId, string $path, string $checksum, string $core = '', ?string $name = null): void {
		$game = new Game();
		$game->setUserId($userId);
		$game->setGameKey($key);
		$game->setFileId($fileId);
		$game->setLastPath($path);
		$game->setChecksum($checksum);
		$game->setCore($core === '' ? null : $core);
		$game->setName($name === null || $name === '' ? null : $name);
		$this->updateOrInsert(fn (): int => $this->replace($userId, $key, $fileId, $path, $checksum, $core, $name), $game);
	}

	private function replace(string $userId, string $key, int $fileId, string $path, string $checksum, string $core, ?string $name): int {
		$qb = $this->db->getTypedQueryBuilder();
		$qb->update($this->getTableName())
			->set('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('last_path', $qb->createNamedParameter($path))
			->set('checksum', $qb->createNamedParameter($checksum));
		if ($core !== '') {
			$qb->set('core', $qb->createNamedParameter($core));
		}
		if ($name !== null) {
			$qb->set('name', $name === '' ? $qb->createNamedParameter(null) : $qb->createNamedParameter($name));
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
		$this->insertUnlessThere($game);
	}

	/**
	 * Drop what is remembered of a game, by the names it is filed under.
	 */
	public function remove(string $userId, string ...$keys): void {
		if ($keys === []) {
			return;
		}
		$qb = $this->db->getTypedQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('game_key', $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getTypedQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
