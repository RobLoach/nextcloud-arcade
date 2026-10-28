<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Service\AppDataCache;
use OCA\Arcade\Service\Caches;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * The cache kept in files, for an instance with no memory cache.
 */
class AppDataCacheTest extends TestCase {
	/** The app data: folder => file name => content. */
	private array $folders = [];

	private function appData(): IAppData {
		$appData = $this->createStub(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(function (string $name): ISimpleFolder {
			if (!isset($this->folders[$name])) {
				throw new NotFoundException($name);
			}
			return $this->folder($name);
		});
		$appData->method('newFolder')->willReturnCallback(function (string $name): ISimpleFolder {
			$this->folders[$name] ??= [];
			return $this->folder($name);
		});
		return $appData;
	}

	private function folder(string $name): ISimpleFolder {
		$folder = $this->createStub(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (string $file): bool => isset($this->folders[$name][$file]));
		$folder->method('getFile')->willReturnCallback(fn (string $file): ISimpleFile => $this->file($name, $file));
		$folder->method('newFile')->willReturnCallback(function (string $file, $content) use ($name): ISimpleFile {
			$this->folders[$name][$file] = (string)$content;
			return $this->file($name, $file);
		});
		$folder->method('getDirectoryListing')->willReturnCallback(
			fn (): array => array_map(fn (string $file): ISimpleFile => $this->file($name, $file), array_keys($this->folders[$name] ?? [])),
		);
		$folder->method('delete')->willReturnCallback(function () use ($name): void {
			unset($this->folders[$name]);
		});
		return $folder;
	}

	private function file(string $folder, string $name): ISimpleFile {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->folders[$folder][$name]);
		$file->method('putContent')->willReturnCallback(function ($content) use ($folder, $name): void {
			$this->folders[$folder][$name] = (string)$content;
		});
		$file->method('delete')->willReturnCallback(function () use ($folder, $name): void {
			unset($this->folders[$folder][$name]);
		});
		return $file;
	}

	private function cache(): AppDataCache {
		return new AppDataCache($this->appData(), Application::CACHE_LIBRARY);
	}

	public function testWhatIsKeptIsGivenBack(): void {
		$cache = $this->cache();
		$this->assertNull($cache->get('scan|alice'));
		$this->assertTrue($cache->set('scan|alice', ['games' => ['Mario.nes']], 3600));
		$this->assertSame(['games' => ['Mario.nes']], $cache->get('scan|alice'));
		$this->assertSame(['games' => ['Mario.nes']], $this->cache()->get('scan|alice'), 'and by the next request');
		$this->assertTrue($cache->hasKey('scan|alice'));
	}

	public function testAnEntryThatExpiredIsGoneAndPruned(): void {
		$cache = $this->cache();
		$cache->set('old', 'scan', 3600);
		$cache->set('kept', 'scan', 0);
		$folder = array_key_first($this->folders);
		foreach ($this->folders[$folder] as $name => $content) {
			$entry = unserialize($content);
			if ($entry['key'] === 'old') {
				$entry['expires'] = time() - 1;
				$this->folders[$folder][$name] = serialize($entry);
			}
		}

		$this->assertSame(1, AppDataCache::pruneAll($this->appData()));
		$this->assertNull($cache->get('old'));
		$this->assertSame('scan', $cache->get('kept'));
	}

	public function testEntriesAreRemovedOneByOneOrAllAtOnce(): void {
		$cache = $this->cache();
		$cache->set('scan|alice', 'a');
		$cache->set('scan|bob', 'b');
		$cache->set('other', 'c');

		$cache->remove('scan|alice');
		$this->assertNull($cache->get('scan|alice'));
		$cache->clear('scan|');
		$this->assertNull($cache->get('scan|bob'));
		$this->assertSame('c', $cache->get('other'));
		$cache->clear();
		$this->assertNull($cache->get('other'));
	}

	public function testNothingButArraysAndStringsComesBackOut(): void {
		$folder = 'cache_' . Application::CACHE_LIBRARY;
		$this->folders[$folder][hash('sha256', 'evil')] = serialize(['key' => 'evil', 'expires' => 0, 'value' => new \ArrayObject([1])]);
		$value = $this->cache()->get('evil');
		$this->assertInstanceOf(\__PHP_Incomplete_Class::class, $value);
	}

	public function testFilesStandInOnlyWhenThereIsNoMemoryCache(): void {
		$appDataFactory = $this->createStub(IAppDataFactory::class);
		$appDataFactory->method('get')->willReturn($this->appData());
		$memory = $this->createStub(ICache::class);

		$without = $this->createStub(ICacheFactory::class);
		$this->assertInstanceOf(AppDataCache::class, Caches::create($without, Application::CACHE_LIBRARY, $appDataFactory));

		$with = $this->createStub(ICacheFactory::class);
		$with->method('isAvailable')->willReturn(true);
		$with->method('createDistributed')->willReturn($memory);
		$this->assertSame($memory, Caches::create($with, Application::CACHE_LIBRARY, $appDataFactory));
	}
}
