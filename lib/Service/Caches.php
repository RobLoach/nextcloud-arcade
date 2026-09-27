<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Picking the memory cache that actually exists on this instance.
 *
 * Asking for a distributed cache on an instance that has none configured
 * hands back a NullCache, which remembers nothing -- and a library that is
 * "cached" there is rescanned on every request without a word about it.
 * A local cache is only local, but on a single-server instance that is
 * every request there is.
 */
final class Caches {
	/**
	 * The best cache the instance offers under a prefix: the distributed
	 * one when any memory cache is configured, the local one when only
	 * that is, and -- with none at all -- the NullCache the distributed
	 * factory hands back, so callers need no case for "no cache".
	 */
	public static function create(ICacheFactory $cacheFactory, string $prefix): ICache {
		if ($cacheFactory->isAvailable()) {
			return $cacheFactory->createDistributed($prefix);
		}
		if ($cacheFactory->isLocalCacheAvailable()) {
			return $cacheFactory->createLocal($prefix);
		}
		return $cacheFactory->createDistributed($prefix);
	}
}
