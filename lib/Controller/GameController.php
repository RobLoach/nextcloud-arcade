<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\CoreMap;
use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Service\RecentService;
use OCA\Arcade\Service\StateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IRequest;

/**
 * What the app knows about one ROM, for the sidebar of the Files app: the
 * system it is for, what the cartridge calls itself, what the user has
 * played it for, and the saves waiting in its slots.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class GameController extends Controller {
	/** Enough of a checksum to compare by eye; the full one is a mouthful. */
	private const CHECKSUM_CHARS = 8;

	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IFilesMetadataManager $metadataManager,
		private RecentService $recentService,
		private StateService $stateService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/game')]
	public function game(string $file = ''): JSONResponse {
		$userId = $this->userId;
		if ($userId === null || $file === '') {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		try {
			$node = $this->rootFolder->getUserFolder($userId)->get($file);
		} catch (\Throwable) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		if (!$node instanceof File) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$known = $this->metadataOf($node->getId());
		// The mark inside the file has the last word; the name and the
		// folder answer for the games that have not been read yet.
		$system = $known['system'] !== '' ? $known['system'] : (CoreMap::systemForPath($node->getPath()) ?? '');
		if ($system === '') {
			// Not a game, as far as the app can tell.
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$stats = $this->recentService->stats($userId)[$node->getId()]
			?? ['plays' => 0, 'seconds' => 0, 'time' => 0];

		return new JSONResponse([
			'system' => [
				'id' => $system,
				'name' => $this->systemName($system),
			],
			'title' => $known['title'],
			'region' => $known['region'],
			'mapper' => $known['mapper'],
			'checksum' => substr($known['checksum'], 0, self::CHECKSUM_CHARS),
			// The CRC32 is already only eight characters, and it is the id
			// the retro databases key on, so it goes out whole.
			'crc32' => $known['crc32'],
			'playtime' => $stats,
			'states' => $this->stateService->list($userId, $file),
		]);
	}

	/**
	 * What was read out of the ROM itself, empty strings for whatever was
	 * not. A game nothing has been filed for is still a game.
	 *
	 * @return array{system: string, title: string, region: string, mapper: string, checksum: string, crc32: string}
	 */
	private function metadataOf(int $fileId): array {
		$known = [
			'system' => '',
			'title' => '',
			'region' => '',
			'mapper' => '',
			'checksum' => '',
			'crc32' => '',
		];
		try {
			$metadata = $this->metadataManager->getMetadata($fileId);
			foreach ([
				'system' => MetadataListener::SYSTEM,
				'title' => MetadataListener::TITLE,
				'region' => MetadataListener::REGION,
				'mapper' => MetadataListener::MAPPER,
				'checksum' => MetadataListener::CHECKSUM,
				'crc32' => MetadataListener::CRC32,
			] as $field => $key) {
				if ($metadata->hasKey($key)) {
					$known[$field] = $metadata->getString($key);
				}
			}
		} catch (\Throwable) {
			// Nothing has been filed for this file yet.
		}
		return $known;
	}

	/**
	 * How the system is named where the app shows it, the way the library
	 * names its shelves.
	 */
	private function systemName(string $system): string {
		return CoreMap::displayName($system);
	}
}
