<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\ThumbnailService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThumbnailServiceTest extends TestCase {
	private ThumbnailService $service;

	protected function setUp(): void {
		$this->service = new ThumbnailService();
	}

	private function file(string $name, int $id, int $mtime = 0): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		$file->method('getMTime')->willReturn($mtime);
		return $file;
	}

	/**
	 * @param list<File|Folder> $children
	 */
	private function folder(string $name, array $children): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	private function thumbnailsFolder(): Folder {
		return $this->folder('Thumbs', [
			$this->folder('Nintendo - Super Nintendo Entertainment System', [
				// Straight in the platform folder.
				$this->file('Super Mario All-Stars + Super Mario World.png', 101),
				$this->folder('Named_Boxarts', [$this->file('NHL 96 (USA).png', 102)]),
				$this->folder('Named_Titles', [$this->file('NHL 96 (USA).png', 103)]),
			]),
			$this->folder('NES', [$this->file('Super Mario Bros. 3.png', 104)]),
			$this->folder('Sega - Mega Drive - Genesis', [
				$this->file('Jack _ Jill.png', 105),
				$this->file('Sonic The Hedgehog 2 (World).png', 106),
				$this->file('Golden Axe (Europe).png', 107),
				$this->file('Golden Axe (USA).png', 108),
			]),
			// Loose in the thumbnails folder.
			$this->file('Tetris.png', 109),
		]);
	}

	public static function games(): array {
		return [
			'plain image in a No-Intro platform folder, joined with and' => [
				'snes', 'Nintendo - Super Nintendo Entertainment System',
				'Super Mario All-Stars and Super Mario World (Europe).zip', 'plain', 101,
			],
			'libretro box art, game without the region tag' => [
				'snes', 'Nintendo - Super Nintendo Entertainment System', 'NHL 96.zip', 'boxart', 102,
			],
			'libretro title screen' => [
				'snes', 'Nintendo - Super Nintendo Entertainment System', 'NHL 96.zip', 'title', 103,
			],
			'short platform folder name' => ['nes', 'NES', 'Super Mario Bros. 3.nes', 'plain', 104],
			'ampersand written as an underscore' => [
				'genesis', 'Sega - Mega Drive - Genesis', 'Jack & Jill (USA).md', 'plain', 105,
			],
			'leading article and a region tag' => [
				'genesis', 'Sega - Mega Drive - Genesis', 'Sonic the Hedgehog 2.md', 'plain', 106,
			],
			'image loose in the thumbnails folder' => ['gb', '', 'Tetris.gb', 'plain', 109],
			'system found even when the game sits elsewhere' => [
				'nes', 'Some other folder', 'Super Mario Bros. 3.nes', 'plain', 104,
			],
		];
	}

	#[DataProvider('games')]
	public function testForGame(string $system, string $subfolder, string $basename, string $type, int $expected): void {
		$index = $this->service->buildIndex($this->thumbnailsFolder());
		$thumbnails = $this->service->forGame($index, $system, $subfolder, $basename);
		$this->assertSame($expected, $thumbnails[$type] ?? null);
	}

	public function testTheFirstPictureOfEachKindIsTheSampleOfItsSystem(): void {
		$index = $this->service->buildIndex($this->folder('Thumbs', [
			$this->folder('Nintendo - Super Nintendo Entertainment System', [
				$this->folder('Named_Boxarts', [
					$this->file('Chrono Trigger (USA).png', 201),
					$this->file('NHL 96 (USA).png', 202),
				]),
				$this->folder('Named_Titles', [$this->file('NHL 96 (USA).png', 203)]),
				$this->folder('Named_Logos', []),
			]),
			$this->folder('NES', [$this->file('Super Mario Bros. 3.png', 204)]),
			$this->folder('Not a system', [$this->file('Tetris.png', 205)]),
		]));
		$this->assertSame([
			'snes' => ['boxart' => 201, 'title' => 203],
			'nes' => ['plain' => 204],
		], $this->service->samples($index));
	}

	public function testExactNamesWinOverLooseOnes(): void {
		$index = $this->service->buildIndex($this->thumbnailsFolder());
		$thumbnails = $this->service->forGame(
			$index,
			'genesis',
			'Sega - Mega Drive - Genesis',
			'Golden Axe (USA).md',
		);
		$this->assertSame(108, $thumbnails['plain'] ?? null);
	}

	public function testTheWidestReleaseIsPickedWhenSeveralMatchLoosely(): void {
		$index = $this->service->buildIndex($this->thumbnailsFolder());
		$thumbnails = $this->service->forGame($index, 'genesis', 'Sega - Mega Drive - Genesis', 'Golden Axe.md');
		// USA is preferred over Europe.
		$this->assertSame(108, $thumbnails['plain'] ?? null);
	}

	public function testTheNameOnTheCartridgeIsTriedWhenTheFileNameFindsNothing(): void {
		$index = $this->service->buildIndex($this->thumbnailsFolder());
		$this->assertSame(
			[],
			$this->service->forGameNamed($index, 'nes', 'NES', 'rom1.nes', ''),
			'nothing to go on',
		);
		$this->assertSame(
			104,
			$this->service->forGameNamed($index, 'nes', 'NES', 'rom1.nes', 'Super Mario Bros. 3')['plain'] ?? null,
			'found under the name the cartridge gives itself',
		);
	}

	public function testTheFileNameWinsOverTheNameOnTheCartridge(): void {
		$index = $this->service->buildIndex($this->thumbnailsFolder());
		$found = $this->service->forGameNamed($index, 'nes', 'NES', 'Super Mario Bros. 3.nes', 'Something Else');
		$this->assertSame(104, $found['plain'] ?? null);
	}

	public function testTheFolderOfAGameIsTakenFromItsPath(): void {
		$this->assertSame('NES', $this->service->subfolderOf('/Games/NES/Mario.nes', '/Games'));
		$this->assertSame('', $this->service->subfolderOf('/Games/Mario.nes', '/Games'));
		$this->assertSame(
			'Elsewhere',
			$this->service->subfolderOf('/Elsewhere/Mario.nes', '/Games'),
			'a game outside the library keeps the folder it is in',
		);
	}

	public function testGamesWithoutAnImageFindNothing(): void {
		$index = $this->service->buildIndex($this->thumbnailsFolder());
		$this->assertSame([], $this->service->forGame($index, 'nes', 'NES', 'An Unknown Game.nes'));
	}

	public function testScreenshotsAreIndexedByGameAndKeepTheNewest(): void {
		$folder = $this->folder('Screenshots', [
			$this->file('Mario 2026-09-01-10-00-00.png', 201, 1000),
			$this->file('Mario 2026-09-20-18-45-00.png', 202, 2000),
			$this->file('Zelda.png', 203, 1500),
		]);
		$screenshots = $this->service->indexScreenshots($folder);

		$mario = null;
		foreach ($this->service->screenshotKeys('Mario.nes') as $key) {
			$mario ??= $screenshots[$key] ?? null;
		}
		$this->assertSame(202, $mario['id'] ?? null, 'the most recent screenshot is used');

		$zelda = null;
		foreach ($this->service->screenshotKeys('Zelda (USA).nes') as $key) {
			$zelda ??= $screenshots[$key] ?? null;
		}
		$this->assertSame(203, $zelda['id'] ?? null, 'region tags are ignored');
	}
}
