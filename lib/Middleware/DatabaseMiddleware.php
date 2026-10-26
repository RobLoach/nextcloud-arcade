<?php

declare(strict_types=1);

namespace OCA\Arcade\Middleware;

use OCA\Arcade\Controller\ArcadeController;
use OCA\Arcade\Service\Maintenance;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

/**
 * Says so when a request of the app failed because its tables are not
 * what they should be -- an upgrade that went wrong half way leaves a
 * table or a column out -- rather than answering with a bare 500 that
 * the player can only call a failed save.
 *
 * Only asked once a request has already failed, so a request that works
 * costs nothing more.
 */
class DatabaseMiddleware extends Middleware {
	public function __construct(
		private Maintenance $maintenance,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Response
	 * @throws \Exception the exception, when the tables are not to blame
	 */
	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if (!$controller instanceof ArcadeController) {
			throw $exception;
		}
		try {
			$problems = $this->maintenance->databaseProblems();
		} catch (\Throwable) {
			// Not even that can be asked; the original failure says more.
			throw $exception;
		}
		if ($problems === []) {
			throw $exception;
		}
		$this->logger->error('Arcade cannot use its database tables; repair them under Administration settings → Arcade → Maintenance, or with occ arcade:repair', [
			'problems' => $problems,
			'exception' => $exception,
		]);
		return new JSONResponse(['error' => 'database', 'problems' => $problems], Http::STATUS_SERVICE_UNAVAILABLE);
	}
}
