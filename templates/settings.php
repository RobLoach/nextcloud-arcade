<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = $_['settings'];
$fallbacks = $_['fallbacks'];
$buttons = $_['buttons'];
$hotkeys = $_['hotkeys'];
$systems = $_['systems'];
$thumbnailTypes = $_['thumbnailTypes'];
?>

<div id="arcade-settings">
	<div class="section">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('Player'),
		])); ?>
		<p class="settings-hint"><?php p($l->t('Configure how the Arcade retro game player behaves.')); ?></p>
		<?php foreach ([
			'arcade-smooth' => ['video_smooth', $l->t('Smooth video filtering (bilinear)')],
			'arcade-global-events' => ['respond_to_global_events', $l->t('Capture gamepad and keyboard input for the whole page while playing')],
			'arcade-scale-integer' => ['scale_integer', $l->t('Pixel-perfect scaling (whole pixels, with borders)')],
			'arcade-rewind' => ['rewind_enabled', $l->t('Rewind support, going back while the rewind key is held (costs some performance)')],
			'arcade-pause-hidden' => ['pause_when_hidden', $l->t('Pause the game while the tab is in the background')],
			'arcade-autosave' => ['autosave_on_close', $l->t('Save the game automatically when closing the player')],
			'arcade-autoload' => ['autoload_on_start', $l->t('Continue from the latest save when a game starts, without asking')],
		] as $id => [$key, $label]): ?>
			<p class="checkbox-radio-switch">
				<input type="checkbox" id="<?php p($id); ?>" class="checkbox-radio-switch__input arcade-setting"
					data-setting="<?php p($key); ?>" <?php if ($settings[$key]) { p('checked'); } ?>>
				<label for="<?php p($id); ?>"><?php p($label); ?></label>
			</p>
		<?php endforeach; ?>
		<p>
			<label for="arcade-autosave-interval"><?php p($l->t('Save the game to the Auto slot every')); ?></label><br>
			<select id="arcade-autosave-interval" class="arcade-setting" data-setting="autosave_interval">
				<?php foreach ([
					0 => $l->t('Never'),
					30 => $l->t('30 seconds'),
					60 => $l->t('Minute'),
					120 => $l->t('2 minutes'),
					300 => $l->t('5 minutes'),
					600 => $l->t('10 minutes'),
				] as $seconds => $label): ?>
					<option value="<?php p($seconds); ?>"
						<?php if ((int)$settings['autosave_interval'] === $seconds) { p('selected'); } ?>>
						<?php p($label); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="arcade-runahead"><?php p($l->t('Run-ahead, hides input lag at the cost of CPU')); ?></label><br>
			<select id="arcade-runahead" class="arcade-setting" data-setting="runahead_frames">
				<?php foreach ([
					0 => $l->t('Off'),
					1 => $l->t('1 frame'),
					2 => $l->t('2 frames'),
					3 => $l->t('3 frames'),
				] as $frames => $label): ?>
					<option value="<?php p($frames); ?>"
						<?php if ((int)$settings['runahead_frames'] === $frames) { p('selected'); } ?>>
						<?php p($label); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="arcade-fastforward"><?php p($l->t('Fast-forward speed')); ?></label><br>
			<input type="range" id="arcade-fastforward" class="arcade-setting arcade-range"
				data-setting="fastforward_ratio" data-unit="×" min="1" max="5" step="0.5"
				value="<?php p($settings['fastforward_ratio']); ?>">
			<output for="arcade-fastforward"><?php p($settings['fastforward_ratio']); ?>×</output>
		</p>
		<p>
			<label for="arcade-volume"><?php p($l->t('Volume, in decibels, 0 is as recorded')); ?></label><br>
			<input type="range" id="arcade-volume" class="arcade-setting arcade-range"
				data-setting="audio_volume" data-unit=" dB" min="-20" max="10" step="1"
				value="<?php p($settings['audio_volume']); ?>">
			<output for="arcade-volume"><?php p($settings['audio_volume']); ?> dB</output>
		</p>
		<p>
			<label for="arcade-audio-latency"><?php p($l->t('Audio latency, raise it if the sound crackles')); ?></label><br>
			<input type="range" id="arcade-audio-latency" class="arcade-setting arcade-range"
				data-setting="audio_latency" data-unit=" ms" min="16" max="256" step="16"
				value="<?php p($settings['audio_latency']); ?>">
			<output for="arcade-audio-latency"><?php p($settings['audio_latency']); ?> ms</output>
		</p>
	</div>

	<div class="section">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('Controls'),
		])); ?>
		<p class="settings-hint"><?php p($l->t('Click a key to set it, then press the one to use. A key that works a button of the controller is left to the game.')); ?></p>

		<?php /* The button of a binding holds only the key glyph -- "↑", or
		        "—" for no key at all -- so what the key is for is spelled by
		        the label beside it, tied to the button with aria-labelledby.
		        Naming the button after itself as well keeps the glyph in the
		        name: "Up, ↑". */ ?>
		<?php
		$renderKeys = static function (array $bindings, string $kind) use ($l, $settings): void {
			foreach ($bindings as $name => $binding) {
				$id = 'arcade-key-' . $kind . '-' . $name;
				$labelId = 'arcade-key-label-' . $kind . '-' . $name;
				$hintId = 'arcade-key-hint-' . $kind . '-' . $name;
				?>
				<div class="arcade-key">
					<span id="<?php p($labelId); ?>"><?php p($l->t($binding['label'])); ?></span>
					<?php /* The key and what can be done to it travel together, so
					        that a long label wraps within its own column rather
					        than pushing any of them onto a line of their own. */ ?>
					<span class="arcade-key-controls">
						<button type="button" class="arcade-key-binding" id="<?php p($id); ?>"
							aria-labelledby="<?php p($labelId . ' ' . $id); ?>"
							data-kind="<?php p($kind); ?>" data-binding="<?php p($name); ?>"
							data-default="<?php p($binding['default']); ?>"
							data-code="<?php p($settings[$kind][$name] ?? $binding['default']); ?>"></button>
						<?php /* Shown only when there is something to undo or to
						        clear, so a row left alone carries neither. Each
						        says which binding it belongs to, since "Put back"
						        on its own tells a screen reader nothing. */ ?>
						<button type="button" class="arcade-key-action arcade-key-revert" hidden
							aria-labelledby="<?php p($labelId); ?>" data-for="<?php p($id); ?>"
							title="<?php p($l->t('Put this one back to its default')); ?>">↺</button>
						<button type="button" class="arcade-key-action arcade-key-clear" hidden
							aria-labelledby="<?php p($labelId); ?>" data-for="<?php p($id); ?>"
							title="<?php p($l->t('Leave this one on no key at all')); ?>">×</button>
					</span>
					<span class="arcade-key-hint" id="<?php p($hintId); ?>"></span>
				</div>
				<?php
			}
		};
		?>

		<?php /* A reset for each set rather than one for both: the button
		        sits under the set it belongs to, and says which keys it
		        means, so resetting the hot keys cannot take the buttons of
		        the controller with it. */ ?>
		<h3><?php p($l->t('Keys')); ?></h3>
		<div class="arcade-keys">
			<?php $renderKeys($buttons, 'buttons'); ?>
		</div>
		<p>
			<button type="button" class="arcade-keys-reset" data-kind="buttons">
				<?php p($l->t('Put the keys back to their defaults')); ?>
			</button>
		</p>

		<h3><?php p($l->t('Hot Keys')); ?></h3>
		<div class="arcade-keys arcade-keys--wide">
			<?php $renderKeys($hotkeys, 'hotkeys'); ?>
		</div>
		<p>
			<button type="button" class="arcade-keys-reset" data-kind="hotkeys">
				<?php p($l->t('Put the hot keys back to their defaults')); ?>
			</button>
		</p>
	</div>

	<div class="section">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('Folders'),
		])); ?>

		<?php /* One row apiece, written once. Each folder differs only in
		        what it is called, what it is for, and what an empty field
		        leaves in force -- the hint comes from the app rather than
		        being spelled out here, so it cannot drift from what a
		        user without a setting actually gets. */ ?>
		<?php foreach ([
			'library_folder' => [
				$l->t('Games library folder'),
				$l->t('Games in this folder are listed on the Arcade page.'),
			],
			'thumbnails_folder' => [
				$l->t('Thumbnails folder'),
				$l->t('Images in this folder are used as game thumbnails, matched by file name: Mario.png is the thumbnail of Mario.nes. Leave empty to disable.'),
			],
			'saves_folder' => [
				$l->t('Saves folder'),
				$l->t('Save states and battery saves are stored here, under the system and the game. Without a folder, a game cannot be saved at all and the player says so.'),
			],
			'system_folder' => [
				$l->t('System folder'),
				$l->t('BIOS files are read from this folder, by the name the core expects, such as colecovision.rom. Leave empty if no game needs one.'),
			],
			'screenshots_folder' => [
				$l->t('Screenshots folder'),
				$l->t('Screenshots taken in the player are saved here, under the system. Leave empty to download them instead.'),
			],
		] as $key => [$label, $hint]): ?>
			<p>
				<label for="arcade-<?php p($key); ?>"><?php p($label); ?></label><br>
				<em><?php p($hint); ?></em><br>
				<input type="text" id="arcade-<?php p($key); ?>" class="arcade-setting"
					data-setting="<?php p($key); ?>" value="<?php p($settings[$key]); ?>"
					<?php if (($fallbacks[$key] ?? '') !== ''): ?>
						placeholder="<?php p($fallbacks[$key]); ?>"
					<?php endif; ?>>
				<button type="button" class="arcade-folder-picker"
					data-target="arcade-<?php p($key); ?>"><?php p($l->t('Browse …')); ?></button>
				<?php if ($key === 'thumbnails_folder' && $settings['fetch_enabled']): ?>
					<button type="button" id="arcade-fetch-thumbnails"><?php p($l->t('Look for missing box art')); ?></button>
					<span id="arcade-fetch-status" aria-live="polite"></span>
				<?php endif; ?>
			</p>
		<?php endforeach; ?>
	</div>

	<div class="section">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('Picture shown for each system'),
			'hint' => $l->t('Which of the pictures in your thumbnails folder a system is shown with, when it has more than one.'),
		])); ?>
		<?php foreach ($systems as $systemId => $system): ?>
			<p>
				<label for="arcade-thumbnail-<?php p($systemId); ?>"><?php p($system['short']); ?></label><br>
				<select id="arcade-thumbnail-<?php p($systemId); ?>" class="arcade-thumbnail-type"
					data-system="<?php p($systemId); ?>">
					<?php foreach ($thumbnailTypes as $type => $label): ?>
						<option value="<?php p($type); ?>"
							<?php if (($settings['thumbnail_types'][$systemId] ?? 'boxart') === $type) { p('selected'); } ?>>
							<?php p($label); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endforeach; ?>
	</div>
</div>
