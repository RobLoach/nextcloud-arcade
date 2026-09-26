<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = $_['settings'];
$buttons = $_['buttons'];
$hotkeys = $_['hotkeys'];
$systems = $_['systems'];
$thumbnailTypes = $_['thumbnailTypes'];
?>

<div id="arcade-settings">
	<div class="section">
		<h2 class="inlineblock"><?php p($l->t('Player')); ?></h2>
		<span class="msg" aria-live="polite"></span>
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
		<h2 class="inlineblock"><?php p($l->t('Controls')); ?></h2>
		<span class="msg" aria-live="polite"></span>
		<p class="settings-hint"><?php p($l->t('Click a key to set it, then press the one to use. A key that works a button of the controller is left to the game.')); ?></p>

		<h4><?php p($l->t('Keys')); ?></h4>
		<div class="arcade-keys">
			<?php foreach ($buttons as $name => $button): ?>
				<div class="arcade-key">
					<span><?php p($l->t($button['label'])); ?></span>
					<button type="button" class="arcade-key-binding"
						data-kind="buttons" data-binding="<?php p($name); ?>"
						data-default="<?php p($button['default']); ?>"
						data-code="<?php p($settings['buttons'][$name] ?? $button['default']); ?>"></button>
				</div>
			<?php endforeach; ?>
		</div>

		<h4><?php p($l->t('Hot Keys')); ?></h4>
		<div class="arcade-keys">
			<?php foreach ($hotkeys as $name => $hotkey): ?>
				<div class="arcade-key">
					<span><?php p($l->t($hotkey['label'])); ?></span>
					<button type="button" class="arcade-key-binding"
						data-kind="hotkeys" data-binding="<?php p($name); ?>"
						data-default="<?php p($hotkey['default']); ?>"
						data-code="<?php p($settings['hotkeys'][$name] ?? $hotkey['default']); ?>"></button>
				</div>
			<?php endforeach; ?>
		</div>
		<p>
			<button type="button" id="arcade-keys-reset"><?php p($l->t('Put the keys back as they were')); ?></button>
		</p>
	</div>

	<div class="section">
		<h2 class="inlineblock"><?php p($l->t('Folders')); ?></h2>
		<span class="msg" aria-live="polite"></span>
		<p>
			<label for="arcade-library-folder"><?php p($l->t('Games library folder')); ?></label><br>
			<em><?php p($l->t('Games in this folder are listed on the Arcade page.')); ?></em><br>
			<input type="text" id="arcade-library-folder" class="arcade-setting"
				data-setting="library_folder" placeholder="/Games"
				value="<?php p($settings['library_folder']); ?>">
			<button type="button" class="arcade-folder-picker"
				data-target="arcade-library-folder"><?php p($l->t('Browse …')); ?></button>
		</p>
		<p>
			<label for="arcade-thumbnails-folder"><?php p($l->t('Thumbnails folder')); ?></label><br>
			<em><?php p($l->t('Images in this folder are used as game thumbnails, matched by file name: Mario.png is the thumbnail of Mario.nes. Leave empty to disable.')); ?></em><br>
			<input type="text" id="arcade-thumbnails-folder" class="arcade-setting"
				data-setting="thumbnails_folder"
				value="<?php p($settings['thumbnails_folder']); ?>">
			<button type="button" class="arcade-folder-picker"
				data-target="arcade-thumbnails-folder"><?php p($l->t('Browse …')); ?></button>
			<?php if ($settings['fetch_enabled']): ?>
				<button type="button" id="arcade-fetch-thumbnails"><?php p($l->t('Look for missing box art')); ?></button>
				<span id="arcade-fetch-status" aria-live="polite"></span>
			<?php endif; ?>
		</p>
		<p>
			<label for="arcade-saves-folder"><?php p($l->t('Saves folder')); ?></label><br>
			<em><?php p($l->t('Save states and battery saves are stored here, under the system and the game. Without a folder, a game cannot be saved at all and the player says so.')); ?></em><br>
			<input type="text" id="arcade-saves-folder" class="arcade-setting"
				data-setting="saves_folder"
				value="<?php p($settings['saves_folder']); ?>">
			<button type="button" class="arcade-folder-picker"
				data-target="arcade-saves-folder"><?php p($l->t('Browse …')); ?></button>
		</p>
		<p>
			<label for="arcade-system-folder"><?php p($l->t('System folder')); ?></label><br>
			<em><?php p($l->t('BIOS files are read from this folder, by the name the core expects, such as colecovision.rom. Leave empty if no game needs one.')); ?></em><br>
			<input type="text" id="arcade-system-folder" class="arcade-setting"
				data-setting="system_folder"
				value="<?php p($settings['system_folder']); ?>">
			<button type="button" class="arcade-folder-picker"
				data-target="arcade-system-folder"><?php p($l->t('Browse …')); ?></button>
		</p>
		<p>
			<label for="arcade-screenshots-folder"><?php p($l->t('Screenshots folder')); ?></label><br>
			<em><?php p($l->t('Screenshots taken in the player are saved here, under the system. Leave empty to download them instead.')); ?></em><br>
			<input type="text" id="arcade-screenshots-folder" class="arcade-setting"
				data-setting="screenshots_folder"
				value="<?php p($settings['screenshots_folder']); ?>">
			<button type="button" class="arcade-folder-picker"
				data-target="arcade-screenshots-folder"><?php p($l->t('Browse …')); ?></button>
		</p>
	</div>

	<div class="section">
		<h2 class="inlineblock"><?php p($l->t('Picture shown for each system')); ?></h2>
		<span class="msg" aria-live="polite"></span>
		<p class="settings-hint">
			<?php p($l->t('Which of the pictures in your thumbnails folder a system is shown with, when it has more than one.')); ?>
		</p>
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
