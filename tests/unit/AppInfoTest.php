<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parts of appinfo/info.xml that nothing else would notice were wrong.
 */
class AppInfoTest extends TestCase {
	private function info(): \SimpleXMLElement {
		$info = simplexml_load_file(dirname(__DIR__, 2) . '/appinfo/info.xml');
		$this->assertNotFalse($info, 'info.xml does not parse');
		return $info;
	}

	public function testTheAppIsLoadedWhileFilesAreWritten(): void {
		// remote.php -- every upload, from the web and from the desktop
		// client alike -- loads only apps of a few types. Without this one
		// the app is not booted there, so CoreMap's mimetypes are never
		// registered with the detector, and an uploaded ROM is filed as
		// application/octet-stream. The Viewer then has no player for it.
		$this->assertTrue(
			isset($this->info()->types->filesystem),
			'without the filesystem type, uploaded ROMs get the wrong mimetype',
		);
	}

	public function testEveryClassTheInfoNamesExists(): void {
		$named = $this->info()->xpath(
			'//repair-steps//step | //commands/command | //settings/admin | //settings/admin-section'
			. ' | //settings/personal | //settings/personal-section'
			. ' | //activity//setting | //activity//provider',
		);
		$this->assertNotEmpty($named, 'the info names no classes at all, which cannot be right');
		foreach ($named as $class) {
			$name = trim((string)$class);
			$this->assertTrue(class_exists($name), "$name is named in info.xml but does not exist");
		}
	}

	/**
	 * A class Nextcloud only learns about from the info is no use until it
	 * is named there, and nothing else would notice it was left out.
	 *
	 * @return array{0: string, 1: string, 2: string}[]
	 */
	public static function declared(): array {
		return [
			'commands' => ['Command', 'Symfony\\Component\\Console\\Command\\Command', '//commands/command'],
			'repair steps' => ['Migration', 'OCP\\Migration\\IRepairStep', '//repair-steps//step'],
			'settings forms' => ['Settings', 'OCP\\Settings\\ISettings', '//settings/admin | //settings/personal'],
			'settings sections' => [
				'Settings',
				'OCP\\Settings\\IIconSection',
				'//settings/admin-section | //settings/personal-section',
			],
			'activity settings' => ['Activity', 'OCP\\Activity\\ISetting', '//activity//setting'],
			'activity providers' => ['Activity', 'OCP\\Activity\\IProvider', '//activity//provider'],
		];
	}

	#[DataProvider('declared')]
	public function testEveryClassOfItsKindIsNamedInTheInfo(string $folder, string $kind, string $xpath): void {
		$named = [];
		foreach ($this->info()->xpath($xpath) ?: [] as $class) {
			$named[trim((string)$class)] = true;
		}

		foreach (glob(dirname(__DIR__, 2) . "/lib/$folder/*.php") ?: [] as $file) {
			$class = 'OCA\\Arcade\\' . $folder . '\\' . basename($file, '.php');
			if (!class_exists($class) || !is_subclass_of($class, $kind)) {
				continue;
			}
			$this->assertArrayHasKey($class, $named, "$class is not named in info.xml");
		}
	}

	public function testTheVersionMatchesThePackage(): void {
		$package = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/package.json'), true);
		$this->assertSame($package['version'] ?? null, (string)$this->info()->version);
	}
}
