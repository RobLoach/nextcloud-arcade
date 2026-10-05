<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Controls;
use PHPUnit\Framework\TestCase;

class ControlsTest extends TestCase {
	public function testEveryBindingHasALabelAndAKey(): void {
		foreach ([Controls::BUTTONS, Controls::HOTKEYS] as $bindings) {
			foreach ($bindings as $name => $binding) {
				$this->assertNotEmpty($binding['label'], "$name has no label");
				$this->assertMatchesRegularExpression(
					'/^[A-Za-z][A-Za-z0-9]*$/',
					$binding['default'],
					"$name is not bound to a key a browser would report",
				);
			}
		}
	}

	public function testNoTwoButtonsShareAKey(): void {
		$keys = array_values(Controls::defaultButtons());
		$this->assertSame(array_unique($keys), $keys);
	}

	public function testThePlayerKeysStayOutOfTheWayOfTheController(): void {
		$this->assertSame(
			[],
			array_intersect(array_values(Controls::defaultHotkeys()), array_values(Controls::defaultButtons())),
			'a key that works a button cannot also reach the player',
		);
	}

	public function testRewindIsAHotkeyWithAKeyOfItsOwn(): void {
		$this->assertSame('Backspace', Controls::defaultHotkeys()['rewind']);
	}

	public function testOnlyKnownBindingsAreKept(): void {
		$sanitized = Controls::sanitize(
			['a' => 'KeyM', 'made_up' => 'KeyM'],
			Controls::BUTTONS,
		);
		$this->assertSame(['a' => 'KeyM'], $sanitized);
	}

	public function testOnlySomethingThatLooksLikeAKeyIsKept(): void {
		$this->assertSame([], Controls::sanitize(['a' => ''], Controls::BUTTONS));
		$this->assertSame([], Controls::sanitize(['a' => 'Key M'], Controls::BUTTONS));
		$this->assertSame([], Controls::sanitize(['a' => '<script>'], Controls::BUTTONS));
		$this->assertSame([], Controls::sanitize(['a' => str_repeat('K', 40)], Controls::BUTTONS));
		$this->assertSame([], Controls::sanitize(['a' => ['KeyM']], Controls::BUTTONS));

		$this->assertSame(['a' => 'F12'], Controls::sanitize(['a' => 'F12'], Controls::BUTTONS));
		$this->assertSame(['up' => 'ArrowUp'], Controls::sanitize(['up' => 'ArrowUp'], Controls::BUTTONS));
	}

	public function testABindingLeftOnNoKeyIsKept(): void {
		// The settings page writes this for a binding the player has
		// cleared. Were it dropped here, the default would come back on
		// the next load and the key could never be given up -- which is
		// why it is a word and not an empty string. No browser reports a
		// key by this name, so nothing ever matches it.
		$this->assertSame(['a' => 'none'], Controls::sanitize(['a' => 'none'], Controls::BUTTONS));
	}
}
