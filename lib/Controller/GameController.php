<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\CoreMap;
use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Service\BiosService;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\RecentService;
use OCA\Arcade\Service\StateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * What the app knows about one ROM, for the sidebar of the Files app: the
 * system it is for, what the cartridge calls itself, what the user has
 * played it for, and the saves waiting in its slots.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class GameController extends ArcadeController {
	/** Enough of a checksum to compare by eye; the full one is a mouthful. */
	private const CHECKSUM_CHARS = 8;

	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IFilesMetadataManager $metadataManager,
		private RecentService $recentService,
		private StateService $stateService,
		private BiosService $biosService,
		private LibraryService $libraryService,
		private SettingsService $settingsService,
		private LoggerInterface $logger,
		protected ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/game')]
	public function game(string $file = ''): JSONResponse {
		$file = $this->romPath($file);
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

		$extension = strtolower(pathinfo($node->getPath(), PATHINFO_EXTENSION));
		$archive = $extension === 'zip';
		$known = $this->metadataOf($node->getId());
		// A game the background pass has not reached yet is read now, for
		// this one file: a sidebar of System and nothing else says less
		// than the cartridge would have, a moment later. Not a zip, though:
		// the front of an archive is the archive's own, and says nothing of
		// the game in it.
		if (!$known['read'] && !$archive) {
			$known = $this->readNow($node) ?? $known;
		}
		// The mark inside the file has the last word; the name and the
		// folder answer for the games that have not been read yet.
		$system = $known['system'] !== '' ? $known['system'] : (CoreMap::systemForPath($node->getPath()) ?? '');
		// A zip, a .bin or a .rom is a game whatever it says, or does not:
		// the player looks inside to find out, and what it found -- the
		// core it ran -- is noted with the saves.
		if ($system === '' && !isset(CoreMap::libraryExtensions()[$extension])) {
			// Not a game, as far as the app can tell.
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$noted = $this->stateService->noted($userId, $file);
		if ($system === '' && $noted['core'] !== '') {
			$system = CoreMap::systemForCore($noted['core']) ?? '';
		}
		$core = $noted['core'] !== '' ? $noted['core'] : (CoreMap::SYSTEMS[$system]['core'] ?? '');

		$stats = $this->recentService->stats($userId)[$node->getId()]
			?? ['plays' => 0, 'seconds' => 0, 'time' => 0];

		return new JSONResponse([
			'system' => $system === '' ? null : [
				'id' => $system,
				'name' => $this->systemName($system),
			],
			// The game inside a zip, as the player found it and its saves
			// are named.
			'inside' => $archive ? $noted['name'] : '',
			'title' => $known['title'],
			'region' => $known['region'],
			'mapper' => $known['mapper'],
			'checksum' => substr($known['checksum'], 0, self::CHECKSUM_CHARS),
			// The CRC32 is already only eight characters, and it is the id
			// the retro databases key on, so it goes out whole.
			'crc32' => $known['crc32'],
			'playtime' => $stats,
			'states' => $this->stateService->list($userId, $file),
			'emulator' => CoreMap::CORE_NAMES[$core] ?? '',
			'thumbnails' => $this->thumbnailsOf($userId, $file, $system, $known['title']),
			'bios' => $system === '' ? [] : $this->biosOf($userId, $system),
		]);
	}

	/**
	 * Read what the ROM says about itself right away, the way the
	 * background pass would. Live first, so a system whose cartridges say
	 * nothing the app reads costs nothing more than its name.
	 *
	 * @return ?array{system: string, title: string, region: string, mapper: string, checksum: string, crc32: string, read: bool}
	 */
	private function readNow(File $node): ?array {
		try {
			$this->metadataManager->refreshMetadata(
				$node,
				IFilesMetadataManager::PROCESS_LIVE | IFilesMetadataManager::PROCESS_BACKGROUND,
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Could not read a ROM for the sidebar', ['exception' => $e]);
			return null;
		}
		return $this->metadataOf($node->getId());
	}

	/**
	 * The pictures of the game in the user's thumbnails folder, by kind,
	 * in the order the kinds are shown: the box first.
	 *
	 * @return array<string, array{fileId: int, path: string}> kind => picture
	 */
	private function thumbnailsOf(string $userId, string $file, string $system, string $title): array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$found = $this->libraryService->thumbnailsOf(
				$userId,
				$userFolder,
				$this->settingsService->getUserSettings($userId),
				$file,
				$system,
				$title,
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Could not look for the pictures of a game', ['exception' => $e]);
			return [];
		}
		// With its path too: the Viewer opens a file by path.
		$ordered = [];
		foreach (['boxart', 'title', 'snap', 'logo'] as $kind) {
			if (!isset($found[$kind])) {
				continue;
			}
			// A storage that is down for the path still has the picture
			// shown: it opens through its id instead, rather than the whole
			// tab going without the game.
			try {
				$node = $userFolder->getFirstNodeById($found[$kind]);
				$path = $node === null ? null : $userFolder->getRelativePath($node->getPath());
			} catch (\Throwable $e) {
				$this->logger->debug('Could not find where a picture of a game is', ['exception' => $e]);
				$path = null;
			}
			$ordered[$kind] = ['fileId' => $found[$kind], 'path' => $path ?? ''];
		}
		return $ordered;
	}

	/**
	 * The BIOS files the system's core asks for, and whether the player
	 * would find each: the user's own system folder first, then the store
	 * of the instance.
	 *
	 * @return list<array{name: string, present: bool, source: ?string}>
	 */
	private function biosOf(string $userId, string $system): array {
		if ((CoreMap::SYSTEMS[$system]['bios'] ?? []) === []) {
			return [];
		}
		try {
			foreach ($this->biosService->statusFor($userId)['systems'] ?? [] as $entry) {
				if ($entry['system']['id'] === $system) {
					return array_map(
						static fn (array $file): array => ['name' => $file['name'], 'present' => $file['present'], 'source' => $file['source']],
						$entry['files'],
					);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Could not look for the BIOS files of a system', ['exception' => $e]);
		}
		return [];
	}

	/**
	 * What was read out of the ROM itself, empty strings for whatever was
	 * not. A game nothing has been filed for is still a game.
	 *
	 * 'read' says whether anything was read out of the file itself yet,
	 * rather than told by its name or the upload.
	 *
	 * @return array{system: string, title: string, region: string, mapper: string, checksum: string, crc32: string, read: bool}
	 */
	private function metadataOf(int $fileId): array {
		$known = [
			'system' => '',
			'title' => '',
			'region' => '',
			'mapper' => '',
			'checksum' => '',
			'crc32' => '',
			'read' => false,
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
			// Read, as the background pass now says it has. Before it said
			// so, only reading the file gave a title, a mapper or a CRC32;
			// the system and the MD5 can come from the name and the upload.
			$known['read'] = $metadata->hasKey(MetadataListener::READ)
				|| $known['title'] !== '' || $known['mapper'] !== '' || $known['crc32'] !== '';
		} catch (\Throwable $e) {
			// Nothing has been filed for this file yet, which is ordinary
			// for a game the background job has not reached. Anything else
			// landing here shows up as a sidebar of empty fields, so it is
			// worth being able to tell the two apart.
			$this->logger->debug('Could not read what the ROM says about itself', [
				'fileId' => $fileId,
				'exception' => $e,
			]);
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
