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
use OCP\Config\IUserConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ThumbnailFetchServiceTest extends TestCase {
	private ThumbnailFetchService $service;

	protected function setUp(): void {
		$this->service = new ThumbnailFetchService(
			$this->createStub(IClientService::class),
			$this->createStub(ICacheFactory::class),
			$this->settingsService(),
			$this->createStub(IUserConfig::class),
			$this->createStub(LoggerInterface::class),
		);
	}

	/** An instance that lets the server go looking. */
	private function settingsService(): SettingsService {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getDefaults')->willReturn(['fetch_enabled' => true]);
		return $settings;
	}

	public function testTheRegionOnTheCartridgeIsTriedBeforeTheUsualOnes(): void {
		$candidates = $this->service->candidates('Mario.nes', 'Japan');
		$withRegion = array_values(array_filter(
			$candidates,
			static fn (string $name): bool => str_contains($name, '('),
		));
		$this->assertSame('Mario (Japan)', $withRegion[0] ?? null);
	}

	public function testWithoutARegionTheUsualOnesAreTried(): void {
		$this->assertSame(
			['Mario', 'Mario (USA)', 'Mario (Europe)', 'Mario (World)', 'Mario (Japan)'],
			$this->service->candidates('Mario.nes'),
		);
	}

	public function testTheNameOfTheGameComesFirst(): void {
		$candidates = $this->service->candidates('Super Mario Bros. (World).nes');
		$this->assertSame('Super Mario Bros. (World)', $candidates[0]);
	}

	public function testAGameWithoutARegionIsLookedForUnderTheUsualOnes(): void {
		$candidates = $this->service->candidates('NHL 96.zip');
		$this->assertSame('NHL 96', $candidates[0]);
		$this->assertContains('NHL 96 (USA)', $candidates);
		$this->assertContains('NHL 96 (Europe)', $candidates);
	}

	public function testTheRegionIsAlsoTriedTheOtherWayAround(): void {
		// The server has the USA release of a game the user has as Europe.
		$candidates = $this->service->candidates('Super Mario World (Europe).sfc');
		$this->assertContains('Super Mario World', $candidates);
		$this->assertContains('Super Mario World (USA)', $candidates);
	}

	public function testTitlesJoinedWithAndAreAlsoTriedWithAPlus(): void {
		$candidates = $this->service->candidates('Super Mario All-Stars and Super Mario World (Europe).zip');
		$this->assertContains('Super Mario All-Stars + Super Mario World (Europe)', $candidates);
		$this->assertContains('Super Mario All-Stars + Super Mario World (USA)', $candidates);
	}

	public function testTitlesJoinedWithAPlusAreAlsoTriedWithAnd(): void {
		$this->assertContains(
			'Sonic and Knuckles',
			$this->service->candidates('Sonic + Knuckles.md'),
		);
	}

	public function testTheCharactersLibretroReplacesAreReplaced(): void {
		$this->assertSame('Jack _ Jill', $this->service->candidates('Jack & Jill.md')[0]);
	}

	public function testNoCandidateIsTriedTwice(): void {
		$candidates = $this->service->candidates('Tetris.gb');
		$this->assertSame(array_unique($candidates), $candidates);
	}

	private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

	/** A service whose every download comes back with the given response. */
	private function respondingService(IResponse $response): ThumbnailFetchService {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createStub(ICache::class));
		return new ThumbnailFetchService(
			$clientService,
			$cacheFactory,
			$this->settingsService(),
			$this->createStub(IUserConfig::class),
			$this->createStub(LoggerInterface::class),
		);
	}

	private function response(string $body, string $type = 'image/png', string $length = ''): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnMap([
			['Content-Length', $length],
			['Content-Type', $type],
		]);
		return $response;
	}

	/** An empty thumbnails folder that lets anything be created in it. */
	private function thumbnailsFolder(): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnCallback(fn (): Folder => $this->thumbnailsFolder());
		$folder->method('newFile')->willReturnCallback(fn (): File => $this->createStub(File::class));
		return $folder;
	}

	/** @return array{fetched: int, missing: int, tried: int, written: list<File>} */
	private function fetchWith(IResponse $response): array {
		return $this->respondingService($response)->fetch(
			'alice',
			[['system' => 'nes', 'path' => '/Games/Mario.nes', 'basename' => 'Mario.nes']],
			$this->thumbnailsFolder(),
			1,
		);
	}

	public function testAPngIsFetchedAndStored(): void {
		$result = $this->fetchWith($this->response(self::PNG_MAGIC . 'the picture'));
		$this->assertSame(1, $result['fetched']);
		$this->assertCount(1, $result['written']);
	}

	public function testAJpegIsAcceptedToo(): void {
		// The server keeps PNGs, but a mirror or proxy may re-encode.
		$result = $this->fetchWith($this->response("\xFF\xD8\xFF" . 'the picture', 'image/jpeg'));
		$this->assertSame(1, $result['fetched']);
	}

	public function testADeclaredOversizeIsRejectedUnread(): void {
		$result = $this->fetchWith($this->response(self::PNG_MAGIC . 'tiny', 'image/png', (string)(5 * 1024 * 1024)));
		$this->assertSame(0, $result['fetched']);
		$this->assertSame(1, $result['missing']);
	}

	public function testAnOversizedBodyIsRejected(): void {
		$body = self::PNG_MAGIC . str_repeat('x', 4 * 1024 * 1024);
		$result = $this->fetchWith($this->response($body));
		$this->assertSame(0, $result['fetched']);
	}

	public function testANonImageContentTypeIsRejected(): void {
		$result = $this->fetchWith($this->response(self::PNG_MAGIC . 'looks fine', 'text/html'));
		$this->assertSame(0, $result['fetched']);
	}

	public function testABodyWithoutImageMagicBytesIsRejected(): void {
		$result = $this->fetchWith($this->response('<html>not a picture</html>'));
		$this->assertSame(0, $result['fetched']);
	}
}
