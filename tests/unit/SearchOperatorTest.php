<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Search\SearchBinaryOperator;
use OCA\Arcade\Search\SearchComparison;
use OCA\Arcade\Search\SearchQuery;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use PHPUnit\Framework\TestCase;

/**
 * The server's query optimizer casts operators to strings to tell clauses
 * apart, without asking whether it can. These make sure it can.
 */
class SearchOperatorTest extends TestCase {
	public function testAComparisonSaysWhatItCompares(): void {
		$comparison = new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'mimetype', 'application/x-gameboy-rom');
		self::assertSame('mimetype eq "application\/x-gameboy-rom"', (string)$comparison);
	}

	public function testDifferentClausesReadDifferently(): void {
		$mime = new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'mimetype', 'application/zip');
		$name = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%.zip');
		self::assertNotSame((string)$mime, (string)$name);
	}

	public function testAnOperatorSpeaksForItsArguments(): void {
		$either = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, [
			new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%.gb'),
			new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%.gbc'),
		]);
		self::assertSame('(name like "%.gb" or name like "%.gbc")', (string)$either);
	}

	public function testNotReadsAsNot(): void {
		$not = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_NOT, [
			new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'name', 'games.json'),
		]);
		self::assertSame('(not name eq "games.json")', (string)$not);
	}

	public function testAQueryIsUnlimitedByDefault(): void {
		$query = new SearchQuery(new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%.gb'));
		self::assertSame(0, $query->getLimit());
	}

	public function testAQueryCarriesTheLimitItWasGiven(): void {
		$query = new SearchQuery(new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%.gb'), 2000);
		self::assertSame(2000, $query->getLimit());
	}

	public function testTheArgumentsCanBeRewritten(): void {
		$operator = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, [
			new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%.gb'),
		]);
		$narrower = [new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'name', 'game.gb')];
		$operator->setArguments($narrower);
		self::assertSame($narrower, $operator->getArguments());
	}
}
