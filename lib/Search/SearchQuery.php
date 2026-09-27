<?php

declare(strict_types=1);

namespace OCA\Arcade\Search;

use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/**
 * A query against the file cache, handed to Folder::search(). The
 * searches of the app take their listing unpaged and unordered, so
 * everything else stays at its default.
 */
class SearchQuery implements ISearchQuery {
	/**
	 * @param int $limit how many rows the database hands back at most,
	 *                   0 for all of them. Unordered, so a capped query
	 *                   returns an arbitrary subset -- only for callers
	 *                   whose heuristic tolerates that.
	 */
	public function __construct(
		private ISearchOperator $operation,
		private int $limit = 0,
	) {
	}

	public function getSearchOperation(): ISearchOperator {
		return $this->operation;
	}

	public function getLimit(): int {
		return $this->limit;
	}

	public function getOffset(): int {
		return 0;
	}

	/**
	 * @return list<ISearchOrder>
	 */
	public function getOrder(): array {
		return [];
	}

	public function getUser(): ?IUser {
		return null;
	}

	public function limitToHome(): bool {
		return false;
	}

	/**
	 * Empty keeps the default columns, which is everything a FileInfo holds.
	 *
	 * @return list<string>
	 */
	public function getSelectFields(): array {
		return [];
	}
}
