<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\CoreMap;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * Finds the images that go with the games of a library.
 *
 * Both a flat folder of images named after the games and the
 * libretro-thumbnails layout are supported:
 *
 *   Thumbs/Mario.png
 *   Thumbs/NES/Mario.png
 *   Thumbs/Nintendo - Nintendo Entertainment System/Named_Boxarts/Mario.png
 *   Thumbs/Nintendo - Nintendo Entertainment System/Named_Titles/Mario.png
 *
 * The whole folder is indexed in one pass, so matching a game is a lookup
 * instead of a query per candidate file.
 *
 * @psalm-type Images = array{
 *     exact: array<string, int>,
 *     loose: array<string, array{id: int, rank: int, length: int}>
 * }
 * @psalm-type Index = array{
 *     paths: array<string, array<string, Images>>,
 *     systems: array<string, string>
 * }
 */
class ThumbnailService {
	private const MAX_DEPTH = 3;
	private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

	/** libretro-thumbnails folder name => thumbnail type */
	private const TYPE_FOLDERS = [
		'named_boxarts' => 'boxart',
		'named_titles' => 'title',
		'named_snaps' => 'snap',
		'named_logos' => 'logo',
	];

	/**
	 * Index every image of a thumbnails folder, by folder path and type.
	 *
	 * @return Index
	 */
	public function buildIndex(Folder $folder): array {
		$index = ['paths' => [], 'systems' => []];
		$this->indexFolder($folder, '', $index, 0);
		return $index;
	}

	/**
	 * The folder a game sits in, as the matching wants it: what is left of
	 * its path once the library folder in front of it is taken off.
	 */
	public function subfolderOf(string $path, string $libraryPath): string {
		$library = '/' . trim($libraryPath, '/');
		$relative = str_starts_with($path, $library . '/') ? substr($path, strlen($library)) : $path;
		return trim(dirname($relative), '/.');
	}

	/**
	 * The images of a game, by the names it may be filed under: the name of
	 * the file first, then what the cartridge calls itself, which is all a
	 * game named "rom1.gb" has to go on.
	 *
	 * @param Index $index
	 * @return array<string, int> type => file id
	 */
	public function forGameNamed(
		array $index,
		string $systemId,
		string $subfolder,
		string $basename,
		string $title = '',
	): array {
		$found = $this->forGame($index, $systemId, $subfolder, $basename);
		if ($found === [] && $title !== '') {
			// A title is not a file name: "Super Mario Bros. 3" would lose
			// its 3 to anything that goes looking for an extension.
			$found = $this->forGame($index, $systemId, $subfolder, $title, true);
		}
		return $found;
	}

	/**
	 * The images of a game, by type: boxart, title, snap, logo or plain.
	 *
	 * An exact file name match wins. Failing that, a loose match ignores
	 * region and revision tags, articles and punctuation, so that
	 * "Batman Returns.zip" finds "Batman Returns (USA).png".
	 *
	 * @param Index $index
	 * @return array<string, int> type => file id
	 */
	public function forGame(
		array $index,
		string $systemId,
		string $subfolder,
		string $basename,
		bool $isTitle = false,
	): array {
		$stem = $isTitle ? $basename : pathinfo($basename, PATHINFO_FILENAME);
		$exact = $this->stemToKey($stem);
		$loose = $this->looseStem($stem);

		// The platform folder of the system first, then the folder the game
		// itself is in, then the root of the thumbnails folder.
		$candidates = [];
		if (isset($index['systems'][$systemId])) {
			$candidates[] = $index['systems'][$systemId];
		}
		$path = $this->normalize(trim($subfolder, '/'));
		while ($path !== '') {
			$candidates[] = $path;
			$path = substr($path, 0, (int)strrpos($path, '/'));
		}
		$candidates[] = '';

		$thumbnails = [];
		foreach ($candidates as $candidate) {
			foreach ($index['paths'][$candidate] ?? [] as $type => $images) {
				if (isset($thumbnails[$type])) {
					continue;
				}
				if (isset($images['exact'][$exact])) {
					$thumbnails[$type] = $images['exact'][$exact];
				} elseif ($loose !== '' && isset($images['loose'][$loose])) {
					$thumbnails[$type] = $images['loose'][$loose]['id'];
				}
			}
		}
		return $thumbnails;
	}

	/**
	 * @param Index $index
	 */
	private function indexFolder(Folder $folder, string $path, array &$index, int $depth): void {
		if ($depth > self::MAX_DEPTH) {
			return;
		}
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (!$node instanceof Folder) {
				if ($this->isImage($name)) {
					$index['paths'][$path]['plain'] ??= ['exact' => [], 'loose' => []];
					$this->addImage($index['paths'][$path]['plain'], $name, $node->getId());
				}
				continue;
			}

			$type = self::TYPE_FOLDERS[mb_strtolower($name)] ?? null;
			if ($type !== null) {
				$index['paths'][$path][$type] = $this->indexImages($node);
				continue;
			}
			unset($type);

			$childPath = $path === '' ? $this->normalize($name) : $path . '/' . $this->normalize($name);
			// "Nintendo - Super Nintendo Entertainment System" and "SNES"
			// both point at the same system.
			$system = CoreMap::systemForFolderName($name);
			if ($system !== null && !isset($index['systems'][$system])) {
				$index['systems'][$system] = $childPath;
			}
			$this->indexFolder($node, $childPath, $index, $depth + 1);
		}
	}

	/**
	 * @return Images
	 */
	private function indexImages(Folder $folder): array {
		$images = ['exact' => [], 'loose' => []];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder && $this->isImage($node->getName())) {
				$this->addImage($images, $node->getName(), $node->getId());
			}
		}
		return $images;
	}

	/**
	 * @param Images $images
	 */
	private function addImage(array &$images, string $name, int $id): void {
		$images['exact'][$this->stemKey($name)] = $id;

		$loose = $this->looseKey($name);
		if ($loose === '') {
			return;
		}
		// Several files can share a loose key, such as the USA and the
		// Europe release. Keep the most widely useful one, so the choice
		// does not depend on the order the folder is read in.
		$candidate = [
			'id' => $id,
			'rank' => $this->regionRank($name),
			'length' => mb_strlen($name),
		];
		$current = $images['loose'][$loose] ?? null;
		if ($current === null
			|| $candidate['rank'] < $current['rank']
			|| ($candidate['rank'] === $current['rank'] && $candidate['length'] < $current['length'])) {
			$images['loose'][$loose] = $candidate;
		}
	}

	/**
	 * How preferable the release a file name refers to is, lower is better.
	 */
	private function regionRank(string $name): int {
		$lower = mb_strtolower($name);
		foreach (['(world)' => 0, '(usa' => 1, '(u)' => 1, '(europe' => 2, '(e)' => 2, '(japan' => 3, '(j)' => 3] as $needle => $rank) {
			if (str_contains($lower, $needle)) {
				return $rank;
			}
		}
		return 4;
	}

	/**
	 * A forgiving match key: no extension, no "(USA)" or "[!]" tags, no
	 * leading or trailing article, no punctuation, so "The Legend of
	 * Zelda.nes" and "Legend of Zelda, The (USA) (Rev 1).png" both become
	 * "legend of zelda".
	 *
	 * Titles join their parts with "+", "&" or "and" interchangeably, and
	 * libretro-thumbnails turns "&" into an underscore, so all of them are
	 * dropped: "Super Mario All-Stars and Super Mario World (Europe).zip"
	 * matches "Super Mario All-Stars + Super Mario World.png".
	 */
	private function looseKey(string $name): string {
		return $this->looseStem(pathinfo($name, PATHINFO_FILENAME));
	}

	private function looseStem(string $stem): string {
		$value = $this->transliterate(mb_strtolower($stem));
		$value = preg_replace('/[(\[][^)\]]*[)\]]/u', ' ', $value) ?? $value;
		$value = preg_replace('/,\s*(the|a|an)\s*$/u', '', trim($value)) ?? $value;
		$value = preg_replace('/^(the|a|an)\s+/u', '', $value) ?? $value;
		$value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
		$words = array_filter(
			explode(' ', $value),
			static fn (string $word): bool => $word !== '' && $word !== 'and',
		);
		return implode(' ', $words);
	}

	private function isImage(string $name): bool {
		return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
	}

	/**
	 * The name a screenshot was taken under, without its extension and
	 * without the moment the player took it, or null for a node that is
	 * no image at all.
	 */
	private function screenshotStem(Node $node): ?string {
		if (!$this->isImage($node->getName())) {
			return null;
		}
		$stem = pathinfo($node->getName(), PATHINFO_FILENAME);
		return preg_replace('/\s\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/', '', $stem) ?? $stem;
	}

	/**
	 * The key a file name is matched by: without its extension, lowercase,
	 * and with the characters libretro-thumbnails replaces with an
	 * underscore already replaced, so "Mario Bros: 3.nes" matches
	 * "Mario Bros_ 3.png".
	 */
	private function stemKey(string $name): string {
		return $this->stemToKey(pathinfo($name, PATHINFO_FILENAME));
	}

	private function stemToKey(string $stem): string {
		return $this->normalize(preg_replace('/[&*\/:`<>?\\\\|]/', '_', $stem) ?? $stem);
	}

	/**
	 * Index a folder of screenshots by game, keeping the most recent one.
	 * Screenshots taken by the player are named after the game and the
	 * moment they were taken, which is stripped here.
	 *
	 * @return array<string, array{id: int, mtime: int}> game key => screenshot
	 */
	public function indexScreenshots(Folder $folder): array {
		$screenshots = [];
		foreach ($this->imagesIn($folder) as $node) {
			$stem = $this->screenshotStem($node);
			if ($stem === null) {
				continue;
			}
			$mtime = $node->getMTime();
			foreach ([$this->stemToKey($stem), $this->looseStemKey($stem)] as $key) {
				if ($key !== '' && ($screenshots[$key]['mtime'] ?? -1) < $mtime) {
					$screenshots[$key] = ['id' => $node->getId(), 'mtime' => $mtime];
				}
			}
		}
		return $screenshots;
	}

	/**
	 * The keys a game is looked up by in a screenshot index.
	 *
	 * @return list<string>
	 */
	public function screenshotKeys(string $basename): array {
		return array_values(array_filter([$this->stemKey($basename), $this->looseKey($basename)]));
	}

	/**
	 * Every screenshot of a game, most recent first.
	 *
	 * @return list<array{fileId: int, basename: string, mtime: int}>
	 */
	public function screenshotsFor(Folder $folder, string $basename): array {
		$keys = $this->screenshotKeys($basename);
		$screenshots = [];
		foreach ($this->imagesIn($folder) as $node) {
			$stem = $this->screenshotStem($node);
			if ($stem === null) {
				continue;
			}
			if (!in_array($this->stemToKey($stem), $keys, true)
				&& !in_array($this->looseStemKey($stem), $keys, true)) {
				continue;
			}
			$screenshots[] = [
				'fileId' => $node->getId(),
				'basename' => $node->getName(),
				'mtime' => $node->getMTime(),
			];
		}
		usort($screenshots, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
		return $screenshots;
	}

	/**
	 * The images of a folder and of the folders in it, which is where the
	 * screenshots of a system are kept.
	 *
	 * @return list<Node>
	 */
	private function imagesIn(Folder $folder): array {
		$nodes = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder) {
				$nodes[] = $node;
				continue;
			}
			foreach ($node->getDirectoryListing() as $child) {
				if (!$child instanceof Folder) {
					$nodes[] = $child;
				}
			}
		}
		return $nodes;
	}

	private function looseStemKey(string $stem): string {
		return $this->looseKey($stem . '.png');
	}

	private function normalize(string $value): string {
		return mb_strtolower(trim($value));
	}

	/**
	 * Accents are written both ways in collections, so "Pokémon" and
	 * "Pokemon" have to end up as the same key.
	 */
	private function transliterate(string $value): string {
		if (!preg_match('/[^\x00-\x7F]/', $value)) {
			return $value;
		}
		if (function_exists('transliterator_transliterate')) {
			$transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
			if (is_string($transliterated)) {
				return $transliterated;
			}
		}
		$transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
		return is_string($transliterated) ? mb_strtolower($transliterated) : $value;
	}
}
