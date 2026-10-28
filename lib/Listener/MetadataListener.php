<?php

declare(strict_types=1);

namespace OCA\Arcade\Listener;

use OCA\Arcade\CoreMap;
use OCA\Arcade\RomHeader;
use OCA\Arcade\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\FilesMetadata\AMetadataEvent;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use OCP\FilesMetadata\Event\MetadataLiveEvent;
use Psr\Log\LoggerInterface;

/**
 * What is true of a ROM itself, filed with the file rather than with the
 * user: which system it is for, what the cartridge calls itself, and what
 * it hashes to.
 *
 * Nextcloud keeps this by file id, so it survives a rename and a move, and
 * the indexed keys can be searched. Reading the file is left to the
 * background pass, so scanning a folder of games stays quick.
 *
 * @template-implements IEventListener<AMetadataEvent>
 */
class MetadataListener implements IEventListener {
	/** What is stored, by the name it is stored under. */
	public const SYSTEM = 'arcade-system';
	public const TITLE = 'arcade-title';
	public const REGION = 'arcade-region';
	public const CHECKSUM = 'arcade-md5';
	public const CRC32 = 'arcade-crc32';
	public const MAPPER = 'arcade-mapper';
	/**
	 * That the file was opened and read, whatever it turned out to say: a
	 * cartridge with no title to give is told apart from one nobody has
	 * looked at yet, and is not read again for nothing.
	 */
	public const READ = 'arcade-read';

	/**
	 * Hashing a file that big would cost more than the name it might win.
	 */
	private const MAX_HASH_SIZE = 64 * 1024 * 1024;

	/**
	 * No Virtual Boy cartridge is bigger than this, so anything bigger is
	 * not one, and its tail is not worth a seek.
	 */
	private const MAX_TAIL_SIZE = 16 * 1024 * 1024;

	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AMetadataEvent) {
			return;
		}
		$node = $event->getNode();
		if (!$node instanceof File) {
			return;
		}
		$path = $node->getPath();
		$system = CoreMap::systemForPath($path);
		// A .bin is a ROM of some sort, but of whose is a question for its
		// first bytes rather than for its name. The folder it sits in is a
		// guess; the mark inside the file is not, so the file has the last
		// word even when the folder has already said something.
		$ambiguous = CoreMap::isAmbiguous(pathinfo($path, PATHINFO_EXTENSION));
		if ($system === null && !$ambiguous) {
			return;
		}

		// Both are had without reading a byte of the file: the system from
		// the name, the checksum from what the client sent with the upload.
		$metadata = $event->getMetadata();
		if ($system !== null) {
			$metadata->setString(self::SYSTEM, $system, true);
		}
		$given = $this->givenChecksum($node);
		if ($given !== '') {
			$metadata->setString(self::CHECKSUM, $given, true);
		}

		if ($event instanceof MetadataLiveEvent) {
			// Opening the file is only worth a background job when there is
			// something in it to read.
			$readable = $system !== null && RomHeader::handles($system);
			if ($ambiguous || $readable || ($given === '' && $this->hashingWanted())) {
				$event->requestBackgroundJob();
			}
			return;
		}
		if ($event instanceof MetadataBackgroundEvent) {
			$this->readTheRom($event, $node, $system, $given, $ambiguous);
		}
	}

	private function readTheRom(
		MetadataBackgroundEvent $event,
		File $node,
		?string $system,
		string $given,
		bool $ambiguous,
	): void {
		$metadata = $event->getMetadata();
		$handle = false;
		try {
			$handle = $node->fopen('rb');
			if ($handle === false) {
				return;
			}
			$metadata->setString(self::READ, '1');
			// The file is read once: the front of it is the header, and the
			// rest of the same stream is what is hashed. Systems whose
			// cartridges carry no name the app reads skip the first part.
			$wanted = $ambiguous || $system === null || RomHeader::handles($system);
			// stream_get_contents, not fread: a stream of Nextcloud's hands
			// over 8 KiB a call however much is asked for, and a Super
			// Nintendo header sits 32 KiB in.
			$front = $wanted ? (string)stream_get_contents($handle, RomHeader::BYTES) : '';
			if ($ambiguous || $system === null) {
				// What the cartridge says wins over the folder it sits in.
				$marked = RomHeader::systemOf($front);
				if ($marked !== null) {
					$system = $marked;
					$metadata->setString(self::SYSTEM, $system, true);
				}
			}
			$header = RomHeader::read($front, $system ?? '');
			if ($header['title'] !== '') {
				$metadata->setString(self::TITLE, $header['title'], true);
			}
			if ($header['region'] !== '') {
				$metadata->setString(self::REGION, $header['region'], true);
			}
			// An iNES header carries no title, but it does say which
			// cartridge board the game needs.
			if ($system === 'nes') {
				$mapper = RomHeader::nesMapper($front);
				if ($mapper !== null) {
					$metadata->setString(self::MAPPER, $mapper);
				}
			}

			// When the client sent an MD5 with the upload, the file is never
			// hashed here, so the CRC32 stays absent: reading a whole ROM
			// again only to learn a second name for it would cost more than
			// the name is worth. The games hashed by the app get both.
			if ($given === '' && $this->hashingWanted() && $node->getSize() <= self::MAX_HASH_SIZE) {
				$hashes = $this->hash($handle, $front);
				$metadata->setString(self::CHECKSUM, $hashes['md5'], true);
				$metadata->setString(self::CRC32, $hashes['crc32']);
			}

			// The Virtual Boy writes its header at the END of the file, so
			// its title takes a second, seeked read. It comes after the
			// hash on purpose: the hash wants the stream in one pass.
			if ($system === 'vb' && $header['title'] === '') {
				$title = RomHeader::readTail($this->tail($handle, $node->getSize()), 'vb')['title'];
				if ($title !== '') {
					$metadata->setString(self::TITLE, $title, true);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Could not read the header of a ROM', ['exception' => $e]);
		} finally {
			if ($handle !== false) {
				fclose($handle);
			}
		}
	}

	/**
	 * The last TAIL_BYTES of the file, or nothing when the file is too
	 * small to hold them, too big to be a Virtual Boy cartridge, or the
	 * stream cannot be seeked. Nothing here slurps the whole file.
	 *
	 * @param resource $handle
	 */
	private function tail($handle, int|float $size): string {
		if ($size < RomHeader::TAIL_BYTES || $size > self::MAX_TAIL_SIZE) {
			return '';
		}
		if (@fseek($handle, -RomHeader::TAIL_BYTES, SEEK_END) !== 0) {
			return '';
		}
		return (string)stream_get_contents($handle, RomHeader::TAIL_BYTES);
	}

	/**
	 * The checksum the client worked out on the way up, if it sent one.
	 * Desktop clients do; browsers do not.
	 */
	private function givenChecksum(File $node): string {
		foreach (explode(' ', $node->getChecksum()) as $checksum) {
			if (str_starts_with(strtoupper($checksum), 'MD5:')) {
				return strtolower(substr($checksum, 4));
			}
		}
		return '';
	}

	/**
	 * Working a checksum out means reading the whole ROM, so an
	 * administrator has to ask for it.
	 */
	private function hashingWanted(): bool {
		return (bool)$this->settingsService->getDefaults()['hash_roms'];
	}

	/**
	 * Both hashes from one pass over the stream: the MD5 the app has always
	 * kept, and the CRC32 ('crc32b', the zip polynomial) that the No-Intro
	 * databases key their entries on.
	 *
	 * @param resource $handle the file, already read up to $front
	 * @return array{md5: string, crc32: string} lowercase hex
	 */
	private function hash($handle, string $front): array {
		$md5 = hash_init('md5');
		$crc32 = hash_init('crc32b');
		hash_update($md5, $front);
		hash_update($crc32, $front);
		while (($chunk = fread($handle, 512 * 1024)) !== false && $chunk !== '') {
			hash_update($md5, $chunk);
			hash_update($crc32, $chunk);
		}
		return ['md5' => hash_final($md5), 'crc32' => hash_final($crc32)];
	}
}
