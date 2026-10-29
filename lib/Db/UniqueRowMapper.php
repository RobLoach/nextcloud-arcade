<?php

declare(strict_types=1);

namespace OCA\Arcade\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;

/**
 * A table of one row per user and game, kept so by a unique index: two
 * requests may write the same row at the same moment, and the index is
 * what settles which was first.
 *
 * @template T of Entity
 * @template-extends QBMapper<T>
 */
abstract class UniqueRowMapper extends QBMapper {
	/**
	 * Change a row, or make it when there is none. Another request may
	 * make it between the two, and then it is changed after all.
	 *
	 * An update that changes nothing counts no rows on some databases, so
	 * a row that is there can still be inserted, refused, and updated; the
	 * row ends up as asked either way.
	 *
	 * @param \Closure(): int $update changes the row, saying how many it did
	 * @param T $entity the row to make when there is none
	 */
	protected function updateOrInsert(\Closure $update, Entity $entity): void {
		if ($update() > 0) {
			return;
		}
		if (!$this->insertUnlessThere($entity)) {
			$update();
		}
	}

	/**
	 * Make a row, unless one is there already. Says whether it was made.
	 *
	 * @param T $entity the row to make
	 */
	protected function insertUnlessThere(Entity $entity): bool {
		try {
			$this->insert($entity);
			return true;
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return false;
		}
	}
}
