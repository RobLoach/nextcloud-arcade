<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Settings\DeclarativeAdmin;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

/**
 * The declarative form hands the instance-only settings to the server to
 * render, but the values themselves keep going through SettingsService:
 * the same appconfig keys as ever, and the same bounds on the way in.
 */
class DeclarativeAdminTest extends TestCase {
	/** What the fake appconfig holds, key => value. @var array<string, string> */
	private array $stored = [];

	private function form(): DeclarativeAdmin {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string
				=> $this->stored[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false): bool {
				$this->assertSame(Application::APP_ID, $app, 'the values stay under the app\'s own id');
				$this->stored[$key] = $value;
				return true;
			},
		);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willThrowException(new NotFoundException());
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(
			fn (string $text, $parameters = []): string => vsprintf($text, is_array($parameters) ? $parameters : [$parameters]),
		);
		// Counted wording goes through n(), not t(). Unstubbed it answered
		// with an empty string, which is how a field of options that all
		// said nothing still looked fine from here.
		$l->method('n')->willReturnCallback(
			fn (string $singular, string $plural, int $count): string
				=> str_replace('%n', (string)$count, $count === 1 ? $singular : $plural),
		);
		return new DeclarativeAdmin(
			new SettingsService($this->createStub(IUserConfig::class), $appConfig, $root),
			$l,
		);
	}

	private function user(): IUser {
		return $this->createStub(IUser::class);
	}

	/** The two the app draws itself, as sliders, in templates/admin.php. */
	private const DRAWN_BY_THE_APP = ['max_games', 'max_depth'];

	public function testTheSchemaCoversTheInstanceOnlySettingsItDraws(): void {
		$schema = $this->form()->getSchema();
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_ADMIN, $schema['section_type']);
		$this->assertSame(Application::APP_ID, $schema['section_id'], 'it sits in the existing Arcade section');
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL, $schema['storage_type']);

		// Every instance-only setting is drawn by exactly one of the two:
		// this form, or the app's own template. A setting in neither is a
		// setting nobody can reach.
		$this->assertSame(
			array_values(array_diff(array_keys(SettingsService::INSTANCE_ONLY), self::DRAWN_BY_THE_APP)),
			array_column($schema['fields'], 'id'),
		);
	}

	public function testTheSettingsTheAppDrawsAreStillSavedThroughTheForm(): void {
		// They left the schema for a slider, not the instance: setValue is
		// still what writes them, and still has to clamp them.
		$form = $this->form();
		foreach (self::DRAWN_BY_THE_APP as $key) {
			$form->setValue($key, SettingsService::INSTANCE_ONLY[$key]['max'] * 10, $this->user());
			$this->assertSame(
				SettingsService::INSTANCE_ONLY[$key]['max'],
				$form->getValue($key, $this->user()),
				"$key is still clamped to what it allows",
			);
		}
	}

	public function testTheDefaultsOfTheFormAreTheDefaultsOfTheApp(): void {
		// Nothing stored: what the form shows must be what the app does.
		$form = $this->form();
		$defaults = array_column($form->getSchema()['fields'], 'default', 'id');
		// Whatever the form declares -- the rest the app draws itself, and
		// those are checked against the same bounds just below.
		foreach (array_keys($defaults) as $key) {
			$shown = $form->getValue($key, $this->user());
			// A select is handed its whole option rather than the bare
			// number; what it means is the number inside.
			$this->assertSame(is_array($shown) ? $shown['value'] : $shown, $defaults[$key], $key);
		}
	}

	public function testEveryLengthOfferedForAScanSurvivesBeingSaved(): void {
		// The list is the only thing stopping a value being offered that
		// the bounds would then clamp to something else -- the form would
		// show one length and the instance would keep another.
		$form = $this->form();
		$field = array_column($form->getSchema()['fields'], null, 'id')['cache_ttl'];
		$this->assertSame(DeclarativeSettingsTypes::SELECT, $field['type']);
		$this->assertNotEmpty($field['options']);

		foreach ($field['options'] as $option) {
			// As the select sends it: the option, not the number. Sending
			// the number works too, which is what every other caller does.
			foreach ([$option, $option['value']] as $sent) {
				$form->setValue('cache_ttl', $sent, $this->user());
				$this->assertSame(
					$option['value'],
					$form->getValue('cache_ttl', $this->user())['value'],
					$option['name'] . ' comes back as it went in',
				);
			}
		}
	}

	public function testTheLengthIsShownAsSomethingAnAdministratorCanRead(): void {
		// The form hands this straight to an NcSelect as its model, and an
		// NcSelect draws it by reading `label`. Given the bare number it
		// drew "86400"; given an option without that key, "undefined".
		$form = $this->form();
		$form->setValue('cache_ttl', 86400, $this->user());

		$shown = $form->getValue('cache_ttl', $this->user());

		$this->assertIsArray($shown, 'the whole option goes to the select');
		$this->assertSame(86400, $shown['value']);
		$this->assertNotSame('', $shown['label'], 'and it has something to draw');
	}

	public function testEveryChoiceIsWordedForTheThingThatDrawsIt(): void {
		// The select is an NcSelect, which shows `label`; the server's own
		// docblock for a field asks for `name`. An option with only one of
		// them drew a list of "undefined", once per choice.
		$field = array_column($this->form()->getSchema()['fields'], null, 'id')['cache_ttl'];

		foreach ($field['options'] as $option) {
			$this->assertNotSame('', $option['label'] ?? '', 'every option is drawn with something');
			$this->assertSame($option['name'], $option['label'], 'and says the same either way');
		}
	}

	public function testTheLengthAScanIsKeptByDefaultIsOneOfTheChoices(): void {
		$field = array_column($this->form()->getSchema()['fields'], null, 'id')['cache_ttl'];

		$this->assertContains($field['default'], array_column($field['options'], 'value'));
	}

	public function testALengthSetBeforeTheListStaysOnOffer(): void {
		// The field used to take any number of seconds. Opening the page
		// must not quietly round somebody's choice to the nearest one of
		// ours the next time it is saved.
		$this->stored['cache_ttl'] = '500';

		$field = array_column($this->form()->getSchema()['fields'], null, 'id')['cache_ttl'];

		$this->assertContains(500, array_column($field['options'], 'value'));
	}

	public function testAStoredValueIsReadFromTheOldKeysUnchanged(): void {
		// As written by the app before the form was declarative.
		$this->stored = ['fetch_enabled' => '0', 'max_games' => '250'];
		$form = $this->form();
		$this->assertFalse($form->getValue('fetch_enabled', $this->user()));
		$this->assertSame(250, $form->getValue('max_games', $this->user()));
		$this->assertSame(6, $form->getValue('max_depth', $this->user()), 'the rest keep their defaults');
	}

	public function testAValueIsClampedAndStoredWhereItAlwaysWas(): void {
		$form = $this->form();
		$form->setValue('max_games', 10_000_000, $this->user());
		$form->setValue('hash_roms', true, $this->user());
		$this->assertSame('100000', $this->stored['max_games'] ?? null);
		$this->assertSame('1', $this->stored['hash_roms'] ?? null, 'booleans stay 1/0, as the app stores them');
		$this->assertSame(100000, $form->getValue('max_games', $this->user()));
	}

	public function testAFieldTheFormNeverOfferedIsNotStored(): void {
		$this->form()->setValue('library_folder', '/Elsewhere', $this->user());
		$this->assertSame([], $this->stored);
	}
}
