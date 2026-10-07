<?php

declare(strict_types=1);

namespace OCA\Arcade\Settings;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * The instance-only scalar settings, as a declarative settings form: the
 * server renders and saves it, so the app carries no form code of its own.
 *
 * The values go through SettingsService rather than the server's internal
 * declarative storage. The keys end up the same either way, but this way
 * the bounds of SettingsService::INSTANCE_ONLY are enforced on write --
 * declarative number fields have no min/max of their own -- and whatever
 * an instance stored before this form existed is read back unchanged.
 */
class DeclarativeAdmin implements IDeclarativeSettingsFormWithHandlers {
	public function __construct(
		private SettingsService $settingsService,
		private IL10N $l,
	) {
	}

	public function getSchema(): array {
		$limits = SettingsService::INSTANCE_ONLY;
		return [
			'id' => 'arcade-instance',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('Arcade'),
			'description' => $this->l->t('What holds for every user of this server: box art lookups, ROM checksums, and how far a games library is scanned.'),
			'fields' => [
				[
					'id' => 'fetch_enabled',
					'title' => $this->l->t('Box art'),
					'label' => $this->l->t('Let users look up box art on the libretro thumbnail server'),
					'description' => $this->l->t('This is the only thing the app has the server itself fetch from the internet. Turned off, the button is gone and games are shown with the pictures in your own files.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => 'hash_roms',
					'title' => $this->l->t('ROM checksums'),
					'label' => $this->l->t('Work out the checksum of a ROM the server was not given one for'),
					'description' => $this->l->t('Checksums that arrive with an upload are always kept. Working one out means reading the whole file, in the background, once per game — on object storage that is a download of each ROM.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
				[
					'id' => 'max_games',
					'title' => $this->l->t('Games listed at most'),
					'description' => $this->l->t('Between %1$s and %2$s.', [(string)$limits['max_games']['min'], (string)$limits['max_games']['max']]),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => LibraryService::MAX_GAMES,
				],
				[
					'id' => 'max_depth',
					'title' => $this->l->t('Folders deep at most'),
					'description' => $this->l->t('Between %1$s and %2$s.', [(string)$limits['max_depth']['min'], (string)$limits['max_depth']['max']]),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => LibraryService::MAX_DEPTH,
				],
				[
					'id' => 'cache_ttl',
					'title' => $this->l->t('How long a scan is kept'),
					'description' => $this->l->t('A scan is dropped as soon as anything in the games folder changes, so this is only how long an unchanged one may be reused. Refresh on the library page rebuilds it whatever this says.'),
					'type' => DeclarativeSettingsTypes::SELECT,
					'options' => $this->cacheTtlOptions(),
					'default' => LibraryService::CACHE_TTL,
				],
			],
		];
	}

	/**
	 * The lengths worth offering, rather than a box to type a number of
	 * seconds into: the exact figure never mattered -- an etag drops the
	 * scan long before any of these elapse -- and the old field invited
	 * an admin to tune something that does not want tuning.
	 *
	 * @return list<array{name: string, value: int}>
	 */
	private function cacheTtlOptions(): array {
		$options = [];
		foreach ([1, 2, 6, 12] as $hours) {
			$options[] = [
				'name' => $this->l->n('%n hour', '%n hours', $hours),
				'value' => $hours * 3600,
			];
		}
		foreach ([1, 3] as $days) {
			$options[] = [
				'name' => $this->l->n('%n day', '%n days', $days),
				'value' => $days * 24 * 3600,
			];
		}
		$options[] = ['name' => $this->l->t('1 week'), 'value' => 7 * 24 * 3600];

		// Whatever was set before this was a list -- the field used to take
		// any number of seconds -- stays on offer, so that opening the page
		// and saving it cannot quietly change a choice already made.
		$current = (int)($this->settingsService->getInstanceDefaults()['cache_ttl'] ?? LibraryService::CACHE_TTL);
		if (!in_array($current, array_column($options, 'value'), true)) {
			array_unshift($options, [
				'name' => $this->l->n('%n second', '%n seconds', $current),
				'value' => $current,
			]);
		}
		return $options;
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		return $this->settingsService->getInstanceDefaults()[$fieldId] ?? null;
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		if (!array_key_exists($fieldId, SettingsService::INSTANCE_ONLY)) {
			return;
		}
		// setInstanceDefaults() sanitizes: bounds are clamped, and the value
		// lands on the very appconfig key older versions of the app used.
		$this->settingsService->setInstanceDefaults([$fieldId => $value]);
	}
}
