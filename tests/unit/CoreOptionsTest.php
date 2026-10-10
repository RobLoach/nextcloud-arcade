<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\CoreMap;
use OCA\Arcade\CoreOptions;
use PHPUnit\Framework\TestCase;

class CoreOptionsTest extends TestCase {
	public function testOptionsOnlyTargetCoresThatAreUsed(): void {
		$cores = array_column(CoreMap::SYSTEMS, 'core');
		foreach (array_keys(CoreOptions::OPTIONS) as $core) {
			$this->assertContains($core, $cores, "$core runs no system");
		}
	}

	/**
	 * The prefix each core gives its options. The mednafen cores use the
	 * name of the system rather than the name of the core.
	 */
	private const PREFIXES = [
		'fceumm' => 'fceumm_',
		'snes9x' => 'snes9x_',
		'gambatte' => 'gambatte_',
		'mgba' => 'mgba_',
		'genesis_plus_gx' => 'genesis_plus_gx_',
		'gearsystem' => 'gearsystem_',
		'mednafen_pce_fast' => 'pce_',
		'handy' => 'handy_',
		'mednafen_ngp' => 'ngp_',
		'mednafen_wswan' => 'wswan_',
		'mednafen_vb' => 'vb_',
		'vecx' => 'vecx_',
		'gearcoleco' => 'gearcoleco_',
		'pcsx_rearmed' => 'pcsx_rearmed_',
	];

	public function testOverclockingOffersWhatTheCoreAccepts(): void {
		// Spelled exactly as fceumm lists them: RetroArch passes an
		// unknown value straight to the core, which quietly keeps its
		// default, so a typo here is a setting that does nothing.
		$this->assertSame(
			['disabled', '2x-Postrender', '2x-VBlank'],
			array_keys(CoreOptions::OPTIONS['fceumm']['fceumm_overclocking']['values']),
		);
	}

	public function testEveryOptionIsUsable(): void {
		foreach (CoreOptions::OPTIONS as $core => $options) {
			$this->assertArrayHasKey($core, self::PREFIXES, "$core has no known option prefix");
			foreach ($options as $key => $option) {
				$this->assertStringStartsWith(
					self::PREFIXES[$core],
					$key,
					"$key does not look like an option of $core",
				);
				$this->assertArrayHasKey('label', $option, "$key misses its label");
				$this->assertNotEmpty($option['values'], "$key has no values");
				foreach ($option['values'] as $value => $label) {
					$this->assertIsString($label, "$key has a value without a label");
					// An empty value is reserved for "leave it to the core".
					$this->assertNotSame('', (string)$value, "$key has an empty value");
				}
			}
		}
	}

	public function testSystemsAreListedForEveryCoreWithOptions(): void {
		$systems = CoreOptions::systemsByCore();
		foreach (array_keys(CoreOptions::OPTIONS) as $core) {
			$this->assertNotEmpty($systems[$core] ?? [], "$core lists no system");
		}
	}
}
