<?php

declare(strict_types=1);

/**
 * The server container as OCP\AppFramework\Http\Response reaches for it,
 * internal to the server and so not shipped with the OCP package. All it
 * ever has to hand out here is something with the getTime() the Expires
 * header is computed from, the getId() of the request the headers carry,
 * and the getUser() of the session -- nobody, in a test.
 */
if (!class_exists('OC')) {
	class OC {
		public static object $server;
	}

	OC::$server = new class {
		public function get(string $serviceName): object {
			return new class {
				public function getTime(): int {
					return time();
				}

				public function getId(): string {
					return 'test-request';
				}

				public function getUser(): ?object {
					return null;
				}
			};
		}
	};
}
