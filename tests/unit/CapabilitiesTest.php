<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Capabilities;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\Capabilities\IInitialStateExcludedCapability;
use OCP\Capabilities\IPublicCapability;
use OCP\Config\IUserConfig;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase {
	/**
	 * @param array<string, string> $appConfig what the instance has set
	 */
	private function capabilities(array $appConfig = [], string $version = '0.0.0'): Capabilities {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '', bool $lazy = false) use ($appConfig): string {
				$this->assertSame('arcade', $app);
				// The lazy values are the big JSON blobs, which the server
				// only loads when something asks for them. A capabilities
				// call runs on every page load and has no use for them.
				$this->assertFalse($lazy, "$key was read lazily, which capabilities should never pay for");
				return $appConfig[$key] ?? $default;
			},
		);
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn($version);
		return new Capabilities(
			$appManager,
			new SettingsService(
				$this->createStub(IUserConfig::class),
				$config,
				// Never touched: capabilities ask the config and nothing
				// else. A root folder that would fatal if it were used.
				$this->createStub(IRootFolder::class),
			),
		);
	}

	/** @return array<string, mixed> */
	private function arcade(array $appConfig = [], string $version = '0.0.0'): array {
		$capabilities = $this->capabilities($appConfig, $version)->getCapabilities();
		$this->assertArrayHasKey('arcade', $capabilities);
		$this->assertIsArray($capabilities['arcade']);
		return $capabilities['arcade'];
	}

	/**
	 * The plain authenticated capability: every endpoint of the app needs
	 * a session, so there is nothing to tell an anonymous caller, and the
	 * payload is cheap enough to sit in the initial state of a page.
	 */
	public function testItIsAnAuthenticatedCapability(): void {
		$capabilities = $this->capabilities();
		$this->assertInstanceOf(ICapability::class, $capabilities);
		$this->assertNotInstanceOf(IPublicCapability::class, $capabilities);
		$this->assertNotInstanceOf(IInitialStateExcludedCapability::class, $capabilities);
	}

	public function testTheShapeIsStable(): void {
		$arcade = $this->arcade();
		$this->assertSame(['version', 'systems', 'features', 'limits'], array_keys($arcade));
		$this->assertIsString($arcade['version']);
		$this->assertIsList($arcade['systems']);
		$this->assertSame(['maxGames', 'maxDepth'], array_keys($arcade['limits']));
		$this->assertIsInt($arcade['limits']['maxGames']);
		$this->assertIsInt($arcade['limits']['maxDepth']);
	}

	public function testTheVersionIsTheInstalledOne(): void {
		$this->assertSame('1.2.3', $this->arcade(version: '1.2.3')['version']);
	}

	public function testEveryFeatureIsABoolean(): void {
		$features = $this->arcade()['features'];
		$this->assertNotEmpty($features);
		foreach ($features as $name => $supported) {
			$this->assertIsString($name);
			$this->assertIsBool($supported, "the $name feature is not a boolean");
		}
	}

	public function testTheSystemsAreTheOnesTheAppPlays(): void {
		$systems = $this->arcade()['systems'];
		$this->assertCount(count(CoreMap::SYSTEMS), $systems);
		$this->assertSame(array_keys(CoreMap::SYSTEMS), array_column($systems, 'id'));
		foreach ($systems as $system) {
			$this->assertSame(['id', 'name', 'extensions', 'core', 'bios'], array_keys($system));
			$source = CoreMap::SYSTEMS[$system['id']];
			$this->assertSame($source['label'], $system['name']);
			$this->assertSame($source['extensions'], $system['extensions']);
			$this->assertSame($source['core'], $system['core']);
			$this->assertSame($source['bios'] !== [], $system['bios']);
			$this->assertIsList($system['extensions']);
			$this->assertNotEmpty($system['extensions']);
		}
	}

	/** A system that wants a BIOS, and one that does not. */
	public function testBiosIsWhetherTheSystemAsksForOne(): void {
		$systems = array_column($this->arcade()['systems'], null, 'id');
		$this->assertTrue($systems['gb']['bios']);
		$this->assertFalse($systems['nes']['bios']);
		$this->assertSame('gambatte', $systems['gb']['core']);
	}

	public function testTheLimitsFollowTheInstanceSettings(): void {
		$arcade = $this->arcade(['max_games' => '250', 'max_depth' => '3']);
		$this->assertSame(250, $arcade['limits']['maxGames']);
		$this->assertSame(3, $arcade['limits']['maxDepth']);
	}

	public function testABareConfigGivesTheDefaultLimits(): void {
		$arcade = $this->arcade();
		$this->assertSame(LibraryService::MAX_GAMES, $arcade['limits']['maxGames']);
		$this->assertSame(LibraryService::MAX_DEPTH, $arcade['limits']['maxDepth']);
	}

	/** Nothing in the payload changes between two calls of one request. */
	public function testTheAnswerIsTheSameTwice(): void {
		$capabilities = $this->capabilities(['max_games' => '900']);
		$this->assertSame($capabilities->getCapabilities(), $capabilities->getCapabilities());
	}
}
