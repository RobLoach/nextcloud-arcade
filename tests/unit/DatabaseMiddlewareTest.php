<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Controller\ArcadeController;
use OCA\Arcade\Middleware\DatabaseMiddleware;
use OCA\Arcade\Service\Maintenance;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\DB\Exception as DbException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A request that fails because a table is missing says so; any other
 * failure goes on as it was.
 */
class DatabaseMiddlewareTest extends TestCase {
	private function middleware(array $problems): DatabaseMiddleware {
		$maintenance = $this->createStub(Maintenance::class);
		$maintenance->method('databaseProblems')->willReturn($problems);
		return new DatabaseMiddleware($maintenance, $this->createStub(LoggerInterface::class));
	}

	private function arcadeController(): Controller {
		return new class('arcade', $this->createStub(IRequest::class)) extends ArcadeController {
		};
	}

	public function testAFailureOverAMissingTableSaysSo(): void {
		$response = $this->middleware(['The table oc_arcade_games is missing'])
			->afterException($this->arcadeController(), 'save', new DbException('no such table'));

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(
			['error' => 'database', 'problems' => ['The table oc_arcade_games is missing']],
			$response->getData(),
		);
	}

	public function testADatabaseFailureFurtherDownTheChainCountsToo(): void {
		$response = $this->middleware(['The table oc_arcade_games is missing'])
			->afterException($this->arcadeController(), 'save', new \RuntimeException('could not save', 0, new DbException('no such table')));

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}

	public function testAnyOtherFailureGoesOnAsItWas(): void {
		$failure = new \RuntimeException('something else');
		$this->expectExceptionObject($failure);
		$this->middleware([])->afterException($this->arcadeController(), 'save', $failure);
	}

	public function testAFailureThatIsNotTheDatabasesDoesNotHaveTheTablesLookedAt(): void {
		$maintenance = $this->createMock(Maintenance::class);
		$maintenance->expects($this->never())->method('databaseProblems');
		$failure = new \RuntimeException('The saves folder cannot be made');
		$this->expectExceptionObject($failure);
		(new DatabaseMiddleware($maintenance, $this->createStub(LoggerInterface::class)))
			->afterException($this->arcadeController(), 'save', $failure);
	}

	public function testTheControllersOfOtherAppsAreLeftAlone(): void {
		$failure = new \RuntimeException('not ours');
		$this->expectExceptionObject($failure);
		$other = new class('files', $this->createStub(IRequest::class)) extends Controller {
		};
		$this->middleware(['The table oc_arcade_games is missing'])->afterException($other, 'index', $failure);
	}
}
