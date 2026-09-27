<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\BackgroundJob\FetchThumbnails;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\ThumbnailFetchService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;

/**
 * Asking for the box art of the games that have none, and hearing how it
 * went. The looking itself happens in a background job.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class ThumbnailController extends ArcadeController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settingsService,
		private IJobList $jobList,
		private ThumbnailFetchService $fetchService,
		protected ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	// Queues a background job (deduplicated), so once in a while is all a
	// player needs; thirty an hour still forgives impatient re-clicking
	// many times over.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/arcade/thumbnails/fetch')]
	public function fetch(): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		$settings = $this->settingsService->getUserSettings($this->userId);
		if (!$this->fetchService->isAllowed()) {
			return new JSONResponse(
				['message' => 'Looking up box art is turned off for this instance'],
				Http::STATUS_FORBIDDEN,
			);
		}
		if ($settings['thumbnails_folder'] === '') {
			return new JSONResponse(
				['message' => 'Set a thumbnails folder first'],
				Http::STATUS_PRECONDITION_FAILED,
			);
		}

		$argument = ['userId' => $this->userId];
		if (!$this->jobList->has(FetchThumbnails::class, $argument)) {
			$this->jobList->add(FetchThumbnails::class, $argument);
		}
		$this->fetchService->report($this->userId, 'Looking for box art in the background');
		return new JSONResponse($this->status());
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/thumbnails/fetch')]
	public function status(): JSONResponse|array {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		return [
			...$this->fetchService->status($this->userId),
			'queued' => $this->jobList->has(FetchThumbnails::class, ['userId' => $this->userId]),
		];
	}
}
