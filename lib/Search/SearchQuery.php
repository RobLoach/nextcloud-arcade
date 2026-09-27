<?php

declare(strict_types=1);

namespace OCA\Arcade\Search;

use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/**
 * A query against the file cache, handed to Folder::search(). The one
 * search of the app takes the whole listing unpaged and unordered, so
 * everything but the operation stays at its default.
 */
class SearchQuery implements ISearchQuery {
	public function __construct(
		private ISearchOperator $operation,
	) {
	}

	public function getSearchOperation(): ISearchOperator {
		return $this->operation;
	}

	public function getLimit(): int {
		return 0;
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
