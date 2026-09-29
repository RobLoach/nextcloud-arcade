<?php

declare(strict_types=1);

namespace OCA\Arcade\Preview;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Service\CachePacker;
use OCA\Arcade\Service\Caches;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\ThumbnailService;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\ICacheFactory;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;

/**
 * Gives ROMs the box art of the thumbnails folder as their Nextcloud
 * preview, so a folder of games looks like a shelf of games in the Files
 * app, and anywhere else a preview is shown.
 *
 * Nothing is generated: the picture is one the user already has, only
 * scaled. A game with no box art has no preview, and falls back to the
 * icon of its mimetype.
 */
class RomPreview implements IProviderV2 {
	/** The index of a thumbnails folder is reused across previews. */
	private const INDEX_TTL = 300;

	public function __construct(
		private IRootFolder $rootFolder,
		private SettingsService $settingsService,
		private ThumbnailService $thumbnailService,
		private IFilesMetadataManager $metadataManager,
		private ICacheFactory $cacheFactory,
	) {
	}

	/**
	 * Every ROM mimetype the app knows, as one expression.
	 */
	public static function mimeTypeRegex(): string {
		$mimes = array_map(
			static fn (string $mime): string => preg_quote($mime, '/'),
			array_values(array_unique(array_column(CoreMap::SYSTEMS, 'mime'))),
		);
		return '/^(' . implode('|', $mimes) . ')$/';
	}

	public function getMimeType(): string {
		return self::mimeTypeRegex();
	}

	public function isAvailable(FileInfo $file): bool {
		return $file->getSize() > 0;
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$owner = $file->getOwner()?->getUID();
		if ($owner === null) {
			return null;
		}
		$content = $this->boxArt($owner, $file);
		if ($content === null) {
			return null;
		}

		$image = $this->image();
		$image->loadFromData($content);
		if (!$image->valid()) {
			return null;
		}
		$image->scaleDownToFit($maxX, $maxY);
		return $image;
	}

	/**
	 * What the cartridge calls itself, for a game whose file has been read.
	 * Most have not been, and a game is not left without its box art over
	 * a name it never had.
	 */
	private function title(File $file): string {
		try {
			return $this->metadataManager->getMetadata($file->getId())->getString(MetadataListener::TITLE);
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * OCP\Image is the only way an app can make one, and it takes its
	 * methods from the interface.
	 */
	private function image(): IImage {
		return new Image();
	}

	/**
	 * The picture of the game, if the user has one filed for it.
	 */
	private function boxArt(string $userId, File $file): ?string {
		$settings = $this->settingsService->getUserSettings($userId);
		if ($settings['thumbnails_folder'] === '') {
			return null;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$pictureId = $this->pictureId($userId, $userFolder, $file, $settings);
			if ($pictureId === 0) {
				return null;
			}
			$node = $userFolder->getFirstNodeById($pictureId);
			return $node instanceof File ? $node->getContent() : null;
		} catch (NotFoundException|\Throwable) {
			return null;
		}
	}

	/**
	 * The picture filed for one game, and nothing else.
	 *
	 * The index of a thumbnails folder holds an entry for every image in
	 * it, which for a libretro pack is tens of thousands; unpacking all of
	 * that to answer for one ROM, once per preview and once per size, is
	 * the wrong thing to keep. What is kept instead is the answer: one
	 * number per game, with a zero for "looked, and there is none".
	 *
	 * @param array<string, mixed> $settings
	 */
	private function pictureId(string $userId, Folder $userFolder, File $file, array $settings): int {
		$cache = Caches::create($this->cacheFactory, Application::CACHE_PREVIEW);
		$key = $userId . '|' . $settings['thumbnails_folder'] . '|' . $file->getId();
		$cached = $cache->get($key);
		if (is_int($cached)) {
			return $cached;
		}

		$path = $userFolder->getRelativePath($file->getPath());
		$found = $path === null ? [] : $this->thumbnailService->forGameNamed(
			$this->index($userId, $userFolder, $settings['thumbnails_folder']),
			CoreMap::systemForPath($path) ?? '',
			$this->thumbnailService->subfolderOf($path, $settings['library_folder']),
			basename($path),
			$this->title($file),
		);
		$pictureId = $found === [] ? 0 : (int)reset($found);
		$cache->set($key, $pictureId, self::INDEX_TTL);
		return $pictureId;
	}

	/**
	 * Walking the thumbnails folder for every game that has no answer kept
	 * would be wasteful, so the walk is kept for a few minutes too. Packed,
	 * because the index of a libretro pack runs past the megabyte memcached
	 * drops an entry at without a word.
	 *
	 * @return array<string, mixed>
	 */
	private function index(string $userId, Folder $userFolder, string $thumbnailsPath): array {
		$cache = Caches::create($this->cacheFactory, Application::CACHE_PREVIEW);
		$key = 'index|' . $userId . '|' . $thumbnailsPath;
		$cached = CachePacker::unpack($cache->get($key));
		if ($cached !== null) {
			return $cached;
		}

		$folder = $userFolder->get($thumbnailsPath);
		$index = $folder instanceof Folder ? $this->thumbnailService->buildIndex($folder) : [];
		$cache->set($key, CachePacker::pack($index), self::INDEX_TTL);
		return $index;
	}
}
