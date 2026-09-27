<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\CachePacker;
use PHPUnit\Framework\TestCase;

/**
 * The packing that keeps a big array under the megabyte a memcached
 * entry is allowed, shared by the library scan and the preview index.
 */
class CachePackerTest extends TestCase {
	public function testAnArraySurvivesTheRoundTrip(): void {
		$data = ['paths' => ['nes/mario' => 7], 'systems' => ['nes' => ['mario.png' => 12]]];
		$packed = CachePacker::pack($data);

		$this->assertIsString($packed);
		$this->assertStringStartsWith('gz:', $packed);
		$this->assertSame($data, CachePacker::unpack($packed));
	}

	public function testThePackedEntrySurvivesACacheThatJsonEncodesIt(): void {
		$packed = CachePacker::pack(['some' => 'entry']);
		$this->assertSame(
			$packed,
			json_decode(json_encode($packed)),
			'raw gzip bytes would not survive a distributed cache that json_encodes what it holds',
		);
	}

	public function testALegacyRawGzipEntryStillUnpacks(): void {
		// What a version that stored the bytes unwrapped left behind.
		$raw = gzcompress(json_encode(['still' => 'good']), 6);
		$this->assertSame(['still' => 'good'], CachePacker::unpack($raw));
	}

	public function testAPlainArrayPassesThrough(): void {
		$this->assertSame(['as' => 'it was'], CachePacker::unpack(['as' => 'it was']));
	}

	public function testGarbageUnpacksToNothing(): void {
		$this->assertNull(CachePacker::unpack('not gzip, not json'));
		$this->assertNull(CachePacker::unpack(null));
		$this->assertNull(CachePacker::unpack(42));
	}

	public function testPackingStaysWellUnderTheMemcachedCap(): void {
		$index = ['paths' => [], 'systems' => []];
		for ($i = 0; $i < 40000; $i++) {
			$index['systems']['snes'][sprintf('Some Long Game Title, The (USA) (Rev %05d).png', $i)] = $i;
		}
		$packed = CachePacker::pack($index);

		$this->assertIsString($packed);
		$this->assertLessThan(
			1024 * 1024,
			strlen($packed),
			'a libretro pack of box art must not be dropped at the megabyte without a word',
		);
		$this->assertSame($index, CachePacker::unpack($packed));
	}
}
