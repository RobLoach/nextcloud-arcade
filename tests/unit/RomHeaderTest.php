<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\RomHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a cartridge says about itself, and what it takes for that to be
 * believed.
 */
class RomHeaderTest extends TestCase {
	/**
	 * A stretch of bytes with something written at a given place.
	 */
	private function rom(array $parts, int $size = RomHeader::BYTES): string {
		$data = str_repeat("\x00", $size);
		foreach ($parts as $offset => $bytes) {
			$data = substr_replace($data, $bytes, $offset, strlen($bytes));
		}
		return $data;
	}

	private function gameBoy(string $title): string {
		return $this->rom([
			0x104 => "\xCE\xED\x66\x66",
			0x134 => $title,
		]);
	}

	/**
	 * The header is believed when the checksum and its complement add up.
	 */
	private function superNintendo(string $title, int $country = 0x01, int $offset = 0x7FC0): string {
		$checksum = 0x1234;
		$header = str_pad(substr($title, 0, 21), 21, ' ');
		$header .= str_repeat("\x00", 4);
		$header .= chr($country);
		$header .= str_repeat("\x00", 2);
		$header .= pack('v', $checksum ^ 0xFFFF) . pack('v', $checksum);
		return $this->rom([$offset => $header]);
	}

	public static function marks(): array {
		return [
			'a Mega Drive cartridge' => [[0x100 => 'SEGA MEGA DRIVE '], 'genesis'],
			'a Genesis cartridge' => [[0x100 => 'SEGA GENESIS    '], 'genesis'],
			'a 32X cartridge, which nothing here runs' => [[0x100 => 'SEGA 32X        '], null],
			'an iNES file' => [[0 => "NES\x1a"], 'nes'],
			'a Lynx file' => [[0 => 'LYNX'], 'lynx'],
			'a first-party Neo Geo Pocket cartridge' => [[0 => 'COPYRIGHT BY SNK CORPORATION'], 'ngp'],
			'a third-party Neo Geo Pocket cartridge' => [[0 => ' LICENSED BY SNK CORPORATION'], 'ngp'],
			'a ColecoVision cartridge' => [[0 => "\xAA\x55"], 'coleco'],
			'one that skips its title screen' => [[0 => "\x55\xAA"], 'coleco'],
			'a Game Boy cartridge' => [[0x104 => "\xCE\xED\x66\x66"], 'gb'],
			'a Game Boy Color cartridge' => [[0x104 => "\xCE\xED\x66\x66", 0x143 => "\xC0"], 'gbc'],
			'a Game Boy Advance cartridge' => [[0x04 => "\x24\xFF\xAE\x51"], 'gba'],
			'nothing in particular' => [[0x40 => 'just some bytes'], null],
		];
	}

	#[DataProvider('marks')]
	public function testWhatAFileSaysItIsFor(array $parts, ?string $expected): void {
		$this->assertSame($expected, RomHeader::systemOf($this->rom($parts)));
	}

	public function testASuperNintendoFileIsKnownByItsSumWhenNothingElseMatches(): void {
		$this->assertSame('snes', RomHeader::systemOf($this->superNintendo('A GAME')));
	}

	public function testAnEmptyFileIsForNothing(): void {
		$this->assertNull(RomHeader::systemOf(''));
	}

	public function testAGameBoyCartridgeGivesItsTitle(): void {
		$this->assertSame(
			'SUPER MARIOLAND',
			RomHeader::read($this->gameBoy('SUPER MARIOLAND'), 'gb')['title'],
		);
	}

	public function testAGameBoyTitleIsPaddedWithNothing(): void {
		$this->assertSame('TETRIS', RomHeader::read($this->gameBoy("TETRIS\x00\x00\x00"), 'gb')['title']);
	}

	public function testWithoutTheLogoThereIsNoHeader(): void {
		$data = $this->rom([0x134 => 'NOT A CARTRIDGE']);
		$this->assertSame('', RomHeader::read($data, 'gb')['title']);
	}

	public function testAGameBoyAdvanceCartridgeGivesItsTitle(): void {
		$data = $this->rom([0x04 => "\x24\xFF\xAE\x51", 0xA0 => 'METROID4']);
		$this->assertSame('METROID4', RomHeader::read($data, 'gba')['title']);
	}

	public function testASuperNintendoCartridgeGivesItsTitleAndCountry(): void {
		$header = RomHeader::read($this->superNintendo('SUPER MARIOWORLD'), 'snes');
		$this->assertSame('SUPER MARIOWORLD', $header['title']);
		$this->assertSame('USA', $header['region']);
	}

	public function testASuperNintendoHeaderIsFoundWhereverItIs(): void {
		foreach ([0x7FC0, 0xFFC0, 0x7FC0 + 0x200, 0xFFC0 + 0x200] as $offset) {
			$header = RomHeader::read($this->superNintendo('ZELDA', 0x00, $offset), 'snes');
			$this->assertSame('ZELDA', $header['title'], "not found at $offset");
			$this->assertSame('Japan', $header['region']);
		}
	}

	public function testASuperNintendoHeaderThatDoesNotAddUpIsNotAHeader(): void {
		$data = $this->rom([0x7FC0 => str_pad('LOOKS LIKE A TITLE', 32, "\x01")]);
		$this->assertSame('', RomHeader::read($data, 'snes')['title']);
	}

	public function testAMegaDriveCartridgeIsKnownAbroadByItsOtherName(): void {
		$data = $this->rom([
			0x100 => 'SEGA MEGA DRIVE ',
			0x120 => str_pad('SONIC THE HEDGEHOG', 48),
			0x150 => str_pad('SONIC THE HEDGEHOG 2', 48),
			0x1F0 => 'JUE',
		]);
		$header = RomHeader::read($data, 'genesis');
		$this->assertSame('SONIC THE HEDGEHOG 2', $header['title'], 'the name it goes by abroad');
		$this->assertSame('World', $header['region'], 'sold everywhere');
	}

	public function testAMegaDriveRegionIsSaidTheWayAPictureIsFiled(): void {
		foreach (['U' => 'USA', 'E' => 'Europe', 'J' => 'Japan', 'JU' => 'Japan', 'UE' => 'USA'] as $letters => $region) {
			$data = $this->rom([
				0x100 => 'SEGA',
				0x150 => str_pad('A GAME', 48),
				0x1F0 => (string)$letters,
			]);
			$this->assertSame($region, RomHeader::read($data, 'genesis')['region'], "for $letters");
		}
	}

	public function testAMegaDriveCartridgeFallsBackToItsNameAtHome(): void {
		$data = $this->rom([0x100 => 'SEGA GENESIS    ', 0x120 => str_pad('PUYO PUYO', 48)]);
		$this->assertSame('PUYO PUYO', RomHeader::read($data, 'genesis')['title']);
	}

	public function testALynxFileWithTheLnxHeaderGivesItsTitle(): void {
		$data = $this->rom([0 => 'LYNX', 10 => "California Games\x00\x00", 42 => 'Atari']);
		$header = RomHeader::read($data, 'lynx');
		$this->assertSame('California Games', $header['title']);
		$this->assertSame('', $header['region'], 'the header names no region');
	}

	public function testAHeaderlessLynxDumpSaysNothing(): void {
		$data = $this->rom([0 => "\x00\x00\x81\xEA", 10 => 'NOT A HEADER']);
		$this->assertSame('', RomHeader::read($data, 'lynx')['title']);
	}

	public function testANeoGeoPocketCartridgeGivesItsTitle(): void {
		foreach (['COPYRIGHT BY SNK CORPORATION', ' LICENSED BY SNK CORPORATION'] as $licence) {
			$data = $this->rom([0 => $licence, 0x24 => str_pad('SONIC POCKET', 12)]);
			$header = RomHeader::read($data, 'ngp');
			$this->assertSame('SONIC POCKET', $header['title'], "under '$licence'");
			$this->assertSame('', $header['region'], 'the header names no region');
		}
	}

	public function testWithoutSnksLineThereIsNoNeoGeoPocketHeader(): void {
		$data = $this->rom([0 => 'SOME OTHER CORPORATION      ', 0x24 => 'LOOKS REAL']);
		$this->assertSame('', RomHeader::read($data, 'ngp')['title']);
	}

	public function testAVirtualBoyTailGivesItsTitle(): void {
		$tail = $this->rom([0 => "VIRTUAL BOWLING\x00"], RomHeader::TAIL_BYTES);
		$this->assertSame('VIRTUAL BOWLING', RomHeader::readTail($tail, 'vb')['title']);
	}

	public function testATailTooShortToBeAVirtualBoyHeaderIsNoHeader(): void {
		$this->assertSame('', RomHeader::readTail('VIRTUAL BOWLING', 'vb')['title']);
	}

	public function testATailIsOnlyReadForTheSystemThatHasOne(): void {
		$tail = $this->rom([0 => 'A TITLE'], RomHeader::TAIL_BYTES);
		$this->assertSame(['title' => '', 'region' => ''], RomHeader::readTail($tail, 'gb'));
	}

	public function testAnInesFileGivesItsMapper(): void {
		// MMC3: mapper 4, low nibble in byte 6, none above.
		$data = $this->rom([0 => "NES\x1a", 6 => "\x40"]);
		$this->assertSame('4', RomHeader::nesMapper($data));
	}

	public function testAMapperIsSpreadOverBothItsNibbles(): void {
		// Mapper 65 = 0x41: 1 in byte 6's high nibble, 4 in byte 7's.
		$data = $this->rom([0 => "NES\x1a", 6 => "\x10", 7 => "\x40"]);
		$this->assertSame('65', RomHeader::nesMapper($data));
	}

	public function testANes20HeaderKeepsAThirdNibbleOfItsMapper(): void {
		// Mapper 0x111 = 273, the 2 in bits 2-3 of byte 7 saying NES 2.0.
		$data = $this->rom([0 => "NES\x1a", 6 => "\x10", 7 => "\x18", 8 => "\x01"]);
		$this->assertSame('273', RomHeader::nesMapper($data));
	}

	public function testWithoutTheInesMagicThereIsNoMapper(): void {
		$this->assertNull(RomHeader::nesMapper($this->rom([6 => "\x40"])));
		$this->assertNull(RomHeader::nesMapper("NES\x1a"), 'too short to hold one');
	}

	public function testBinaryWhereTextShouldBeIsNotATitle(): void {
		$this->assertSame('', RomHeader::read($this->gameBoy("\x80\x81\x82"), 'gb')['title']);
	}

	public function testSystemsWithNothingToSayAreLetBe(): void {
		foreach (['nes', 'pce', 'vectrex', 'wonderswan', 'zip'] as $system) {
			$this->assertSame(
				['title' => '', 'region' => ''],
				RomHeader::read($this->gameBoy('SOMETHING'), $system),
				"$system carries no title the app reads",
			);
		}
	}

	public function testAFileTooShortToHoldAHeaderIsNoTrouble(): void {
		$this->assertSame(['title' => '', 'region' => ''], RomHeader::read('', 'snes'));
		$this->assertSame(['title' => '', 'region' => ''], RomHeader::read('a few bytes', 'gb'));
	}
}
