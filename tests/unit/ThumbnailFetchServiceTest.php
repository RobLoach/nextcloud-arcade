<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\ThumbnailFetchService;
use OCP\Http\Client\IClientService;
use OCP\Config\IUserConfig;
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
}
