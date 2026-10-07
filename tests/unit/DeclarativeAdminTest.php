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
		return new DeclarativeAdmin(
			new SettingsService($this->createStub(IUserConfig::class), $appConfig, $root),
			$l,
		);
	}

	private function user(): IUser {
		return $this->createStub(IUser::class);
	}

	public function testTheSchemaCoversExactlyTheInstanceOnlySettings(): void {
		$schema = $this->form()->getSchema();
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_ADMIN, $schema['section_type']);
		$this->assertSame(Application::APP_ID, $schema['section_id'], 'it sits in the existing Arcade section');
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL, $schema['storage_type']);
		$this->assertSame(
			array_keys(SettingsService::INSTANCE_ONLY),
			array_column($schema['fields'], 'id'),
		);
	}

	public function testTheDefaultsOfTheFormAreTheDefaultsOfTheApp(): void {
		// Nothing stored: what the form shows must be what the app does.
		$form = $this->form();
		$defaults = array_column($form->getSchema()['fields'], 'default', 'id');
		foreach (array_keys(SettingsService::INSTANCE_ONLY) as $key) {
			$this->assertSame($form->getValue($key, $this->user()), $defaults[$key], $key);
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
			$form->setValue('cache_ttl', $option['value'], $this->user());
			$this->assertSame(
				$option['value'],
				$form->getValue('cache_ttl', $this->user()),
				$option['name'] . ' comes back as it went in',
			);
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
