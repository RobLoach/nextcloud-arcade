<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

/**
 * How a big array goes into a memory cache and comes back out: gzipped
 * JSON, so tens of thousands of entries stay well under the megabyte a
 * memcached entry is allowed -- anything over it is dropped without a
 * word, and what was "cached" is worked out again on every request.
 */
final class CachePacker {
	/**
	 * The array as it is cached: gzipped JSON, wrapped in base64 because
	 * a distributed cache may run what it holds through json_encode,
	 * which cannot carry raw bytes and would quietly cache nothing at
	 * all. An array that cannot be packed comes back as it was, and is
	 * cached plain.
	 *
	 * @param array<array-key, mixed> $data
	 */
	public static function pack(array $data): mixed {
		$encoded = json_encode($data);
		$compressed = $encoded === false ? false : gzcompress($encoded, 6);
		if ($compressed === false) {
			return $data;
		}
		return 'gz:' . base64_encode($compressed);
	}

	/**
	 * A cached entry back into an array, whichever way it was stored:
	 * gzipped JSON from pack(), raw gzip from before the bytes were
	 * wrapped, or a plain array from a pack() that could not compress.
	 *
	 * @return array<array-key, mixed>|null null when there is no usable entry
	 */
	public static function unpack(mixed $cached): ?array {
		if (is_string($cached)) {
			if (str_starts_with($cached, 'gz:')) {
				$binary = base64_decode(substr($cached, 3), true);
				$encoded = $binary === false ? false : @gzuncompress($binary);
			} else {
				// Cached before the bytes were wrapped for the caches that
				// json_encode what they hold.
				$encoded = @gzuncompress($cached);
			}
			$cached = json_decode($encoded === false ? $cached : $encoded, true);
		}
		return is_array($cached) ? $cached : null;
	}
}
