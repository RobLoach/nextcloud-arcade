<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\Service\Maintenance;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The maintenance section of the administration settings. Every route is
 * for administrators only, which is what a route says by not saying
 * NoAdminRequired.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class MaintenanceController extends ArcadeController {
	public function __construct(
		string $appName,
		IRequest $request,
		private Maintenance $maintenance,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/arcade/maintenance')]
	public function status(): JSONResponse {
		return new JSONResponse($this->maintenance->status());
	}

	#[FrontpageRoute(verb: 'POST', url: '/arcade/maintenance/database')]
	public function repairDatabase(): JSONResponse {
		try {
			return new JSONResponse(['database' => $this->maintenance->repairDatabase()]);
		} catch (\Throwable $e) {
			// Whatever the server said is worth more to the administrator
			// than a bare failure, and they are the one reading it.
			$this->logger->error('Could not repair the database tables of Arcade', ['exception' => $e]);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[FrontpageRoute(verb: 'POST', url: '/arcade/maintenance/filetypes')]
	public function fixFileTypes(): JSONResponse {
		return new JSONResponse($this->maintenance->fixFileTypes());
	}

	#[FrontpageRoute(verb: 'POST', url: '/arcade/maintenance/leftbehind')]
	public function removeLeftBehind(): JSONResponse {
		return new JSONResponse($this->maintenance->removeLeftBehind());
	}

	#[FrontpageRoute(verb: 'POST', url: '/arcade/maintenance/appdata')]
	public function removeAppDataSaves(): JSONResponse {
		return new JSONResponse(['removed' => $this->maintenance->removeAppDataSaves()]);
	}

	#[FrontpageRoute(verb: 'POST', url: '/arcade/maintenance/cloudsync')]
	public function recordForRetroArch(): JSONResponse {
		return new JSONResponse(['queued' => $this->maintenance->recordForRetroArch()]);
	}
}
