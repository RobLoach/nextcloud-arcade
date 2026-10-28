<?php

declare(strict_types=1);

namespace OCA\Arcade\Middleware;

use OCA\Arcade\Controller\ArcadeController;
use OCA\Arcade\Service\Maintenance;
use OCA\Arcade\Service\SavesBeingMovedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * Says so when a request of the app failed because its tables are not
 * what they should be -- an upgrade that went wrong half way leaves a
 * table or a column out -- rather than answering with a bare 500 that
 * the player can only call a failed save.
 *
 * Only asked once a request has already failed, and only when it failed
 * in the database: a request that works costs nothing more, and neither
 * does one that failed for any other reason -- a saves folder that cannot
 * be made, an external storage having a bad day -- however often.
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
		// The saves of an earlier version are being moved just then: said
		// as such, for the player to tell, rather than as a failed save.
		if ($exception instanceof SavesBeingMovedException) {
			return new JSONResponse(['error' => 'moving'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		if (!self::fromTheDatabase($exception)) {
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

	/**
	 * Whether the failure came from the database, here or anywhere down the
	 * chain of what caused it: Nextcloud's own exception, or the Doctrine
	 * one beneath it that some paths let through as it is.
	 */
	private static function fromTheDatabase(\Throwable $exception): bool {
		for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof DbException || str_starts_with($cause::class, 'Doctrine\\DBAL\\')) {
				return true;
			}
		}
		return false;
	}
}
