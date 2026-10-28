<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Picking the memory cache that actually exists on this instance.
 *
 * Asking for a distributed cache on an instance that has none configured
 * hands back a NullCache, which remembers nothing -- and a library that is
 * "cached" there is rescanned on every request without a word about it.
 * A local cache is only local, but on a single-server instance that is
 * every request there is. With neither, files of the app data stand in.
 */
final class Caches {
	/**
	 * The best cache the instance offers under a prefix: the distributed
	 * one when any memory cache is configured, the local one when only
	 * that is, and -- with none at all -- one kept in files of the app
	 * data. Without the app data to keep it in, the NullCache the
	 * distributed factory hands back, so callers need no case for "no
	 * cache".
	 */
	public static function create(ICacheFactory $cacheFactory, string $prefix, ?IAppDataFactory $appDataFactory = null): ICache {
		if ($cacheFactory->isAvailable()) {
			return $cacheFactory->createDistributed($prefix);
		}
		if ($cacheFactory->isLocalCacheAvailable()) {
			return $cacheFactory->createLocal($prefix);
		}
		if ($appDataFactory !== null) {
			return new AppDataCache($appDataFactory->get(Application::APP_ID), $prefix);
		}
		return $cacheFactory->createDistributed($prefix);
	}
}
