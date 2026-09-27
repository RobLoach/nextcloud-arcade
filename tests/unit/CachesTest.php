<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\Caches;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * The guard that keeps the app off the NullCache: the distributed cache
 * when the instance has one, the local one when only that is configured.
 */
class CachesTest extends TestCase {
	private function factory(bool $distributed, bool $local, ?string &$asked = null): ICacheFactory {
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($distributed);
		$factory->method('isLocalCacheAvailable')->willReturn($local);
		$factory->method('createDistributed')->willReturnCallback(
			function (string $prefix) use (&$asked): ICache {
				$asked = "distributed:$prefix";
				return $this->createStub(ICache::class);
			},
		);
		$factory->method('createLocal')->willReturnCallback(
			function (string $prefix) use (&$asked): ICache {
				$asked = "local:$prefix";
				return $this->createStub(ICache::class);
			},
		);
		return $factory;
	}

	public function testADistributedCacheIsTakenWhenThereIsOne(): void {
		Caches::create($this->factory(true, true, $asked), 'arcade_library');
		$this->assertSame('distributed:arcade_library', $asked);
	}

	public function testTheLocalCacheStandsInWhenOnlyItIsConfigured(): void {
		Caches::create($this->factory(false, true, $asked), 'arcade_library');
		$this->assertSame('local:arcade_library', $asked);
	}

	public function testNoCacheAtAllStaysTheNullCacheOfTheDistributedFactory(): void {
		Caches::create($this->factory(false, false, $asked), 'arcade_library');
		$this->assertSame('distributed:arcade_library', $asked, 'callers need no case for "no cache"');
	}
}
