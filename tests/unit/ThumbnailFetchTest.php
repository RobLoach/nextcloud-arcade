<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\ThumbnailFetchService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\Config\IUserConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The looking itself: which names are asked for, what is kept, and what is
 * not asked for twice.
 */
class ThumbnailFetchTest extends TestCase {
	private const USER = 'alice';
	/** What the fake server serves: it has to open like the PNG it claims to be. */
	private const PICTURE = "\x89PNG\r\n\x1a\nthe picture";

	/** The names the server was asked for. */
	private array $asked = [];
	/** What the server has, by the name it is filed under. */
	private array $server = [];
	/** What the thumbnails folder holds afterwards, by path. */
	private array $stored = [];
	/** The games that were looked for in vain before. */
	private array $misses = [];

	private function service(): ThumbnailFetchService {
		$response = $this->createStub(IResponse::class);

		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) {
				$this->asked[] = $url;
				$name = rawurldecode(basename($url, '.png'));
				if (!isset($this->server[$name])) {
					throw new \RuntimeException('404');
				}
				$found = $this->createStub(IResponse::class);
				$found->method('getStatusCode')->willReturn(200);
				$found->method('getBody')->willReturn($this->server[$name]);
				$found->method('getHeader')->willReturnMap([
					['Content-Type', 'image/png'],
				]);
				return $found;
			},
		);
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->misses[$key] ?? null);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value, $ttl = 0): bool {
				$this->misses[$key] = $value;
				return true;
			},
		);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		unset($response);
		return new ThumbnailFetchService($clientService, $cacheFactory, $this->settingsService(), $this->createStub(IUserConfig::class), $this->createStub(LoggerInterface::class));
	}

	/**
	 * A folder that remembers what was put in it, at any depth.
	 */
	private function folder(string $path = ''): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn($path === '' ? 'Thumbs' : basename($path));
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $name): bool => isset($this->stored[$this->join($path, $name)]),
		);
		$folder->method('get')->willReturnCallback(
			function (string $name) use ($path) {
				$child = $this->join($path, $name);
				if (isset($this->stored[$child])) {
					$file = $this->createStub(File::class);
					$file->method('putContent')->willReturnCallback(
						function ($content) use ($child): void {
							$this->stored[$child] = (string)$content;
						},
					);
					return $file;
				}
				throw new NotFoundException($child);
			},
		);
		$folder->method('newFolder')->willReturnCallback(
			fn (string $name): Folder => $this->folder($this->join($path, $name)),
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content = null) use ($path): File {
				$this->stored[$this->join($path, $name)] = (string)$content;
				return $this->createStub(File::class);
			},
		);
		return $folder;
	}

	private function join(string $path, string $name): string {
		return $path === '' ? $name : "$path/$name";
	}

	/**
	 * @return array<string, mixed>
	 */
	private function game(string $basename, string $system = 'nes'): array {
		return ['path' => "/Games/$basename", 'basename' => $basename, 'system' => $system];
	}

	/** An instance that lets the server go looking. */
	private function settingsService(): SettingsService {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getDefaults')->willReturn(['fetch_enabled' => true]);
		return $settings;
	}

	public function testBoxArtIsStoredWhereTheMatchingLooksForIt(): void {
		$this->server['Mario (USA)'] = self::PICTURE;

		$result = $this->service()->fetch(self::USER, [$this->game('Mario.nes')], $this->folder(), 10);

		$this->assertSame(1, $result['fetched']);
		$this->assertSame(
			self::PICTURE,
			$this->stored['Nintendo - Nintendo Entertainment System/Named_Boxarts/Mario.png'] ?? null,
			'filed under the platform and the name of the game as the user has it',
		);
	}

	public function testTheFilesWrittenThisRunAreHandedBack(): void {
		$this->server['Mario (USA)'] = self::PICTURE;

		$result = $this->service()->fetch(
			self::USER,
			[$this->game('Mario.nes'), $this->game('Nothing Doing.nes')],
			$this->folder(),
			10,
		);

		$this->assertCount(1, $result['written'], 'one file for the one game that was found');
		$this->assertContainsOnlyInstancesOf(File::class, $result['written']);
	}

	public function testAnInstanceThatSaysNoIsNotAskedAgain(): void {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getDefaults')->willReturn(['fetch_enabled' => false]);
		$service = new ThumbnailFetchService(
			$this->createStub(IClientService::class),
			$this->createStub(ICacheFactory::class),
			$settings,
			$this->createStub(IUserConfig::class),
			$this->createStub(LoggerInterface::class),
		);

		$result = $service->fetch(self::USER, [$this->game('Mario.nes')], $this->folder(), 10);

		$this->assertSame(0, $result['tried'], 'the server is never asked');
		$this->assertSame([], $this->asked);
	}

	public function testAGameThatIsNowhereIsCountedAndLetGo(): void {
		$result = $this->service()->fetch(self::USER, [$this->game('Nothing Doing.nes')], $this->folder(), 10);

		$this->assertSame(0, $result['fetched']);
		$this->assertSame(1, $result['missing']);
		$this->assertSame([], $this->stored);
	}

	public function testAGameLookedForInVainIsNotAskedAboutAgain(): void {
		$service = $this->service();
		$service->fetch(self::USER, [$this->game('Nothing Doing.nes')], $this->folder(), 10);
		$asked = count($this->asked);

		$service->fetch(self::USER, [$this->game('Nothing Doing.nes')], $this->folder(), 10);
		$this->assertCount($asked, $this->asked, 'the server was left alone the second time');
	}

	public function testOnlyAsManyGamesAsAskedForAreLookedUp(): void {
		$games = [];
		for ($i = 1; $i <= 5; $i++) {
			$games[] = $this->game("Game $i.nes");
		}

		$result = $this->service()->fetch(self::USER, $games, $this->folder(), 2);

		$this->assertSame(2, $result['tried'], 'the batch is the batch');
		$this->assertSame(5, $result['missing'], 'and the rest are still waiting');
	}

	public function testAGameOfAnUnknownSystemHasNowhereToLook(): void {
		$result = $this->service()->fetch(self::USER, [$this->game('Mystery.zip', 'zip')], $this->folder(), 10);

		$this->assertSame(0, $result['tried']);
		$this->assertSame([], $this->asked);
	}

	public function testTheNameOfTheGameIsTriedBeforeItsRegions(): void {
		$this->server['Mario'] = self::PICTURE;
		$this->service()->fetch(self::USER, [$this->game('Mario.nes')], $this->folder(), 10);

		$this->assertCount(1, $this->asked, 'found at the first name, so no more were asked for');
	}

	public function testEachSystemIsLookedForUnderItsOwnPlatform(): void {
		$this->server['Sonic (USA)'] = self::PICTURE;
		$this->service()->fetch(self::USER, [$this->game('Sonic.md', 'genesis')], $this->folder(), 10);

		$this->assertStringContainsString('Sega%20-%20Mega%20Drive%20-%20Genesis', $this->asked[0]);
		$this->assertArrayHasKey('Sega - Mega Drive - Genesis/Named_Boxarts/Sonic.png', $this->stored);
	}
}
