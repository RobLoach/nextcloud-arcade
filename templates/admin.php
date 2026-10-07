<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$defaults = $_['defaults'];
$fallbacks = $_['fallbacks'];
$limits = $_['limits'];
$coreOptions = $_['coreOptions'];
$systemsByCore = $_['systemsByCore'];
$settings = ['core_options' => $_['storedCoreOptions']];
$systems = $_['systems'];
$thumbnailTypes = $_['thumbnailTypes'];
$storedTypes = $_['storedThumbnailTypes'];
$systemFolder = $_['systemFolder'];
?>

<div id="arcade-settings" data-scope="admin">
	<div class="section">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('Folders'),
			'hint' => $l->t('The folders users start with. Everybody can pick their own afterwards. Each path is read inside every user\'s own files, so Browse is only picking the name — a folder only you have leaves everybody else with nothing to show.'),
		])); ?>

		<?php foreach ([
			'library_folder' => $l->t('Games library folder'),
			'thumbnails_folder' => $l->t('Thumbnails folder'),
			'saves_folder' => $l->t('Saves folder'),
			'screenshots_folder' => $l->t('Screenshots folder'),
			'system_folder' => $l->t('System folder, for BIOS files'),
		] as $key => $label): ?>
			<p>
				<label for="arcade-<?php p($key); ?>"><?php p($label); ?></label><br>
				<?php /* The hint says what an empty field leaves in force: a
				        user with nothing set still gets the app's own
				        folder, and a blank box read as though none did. */ ?>
				<input type="text" id="arcade-<?php p($key); ?>" class="arcade-setting"
					data-setting="<?php p($key); ?>" value="<?php p($defaults[$key]); ?>"
					<?php if (($fallbacks[$key] ?? '') !== ''): ?>
						placeholder="<?php p($fallbacks[$key]); ?>"
					<?php endif; ?>>
				<button type="button" class="arcade-folder-picker"
					data-target="arcade-<?php p($key); ?>"><?php p($l->t('Browse …')); ?></button>
			</p>
		<?php endforeach; ?>

		<?php /* How far into the library folder a scan goes, asked where
		        the folder itself is asked for. Sliders rather than boxes
		        to type a number into: both are bounded, neither wants an
		        exact figure, and the ends say what the limits are without
		        a line of prose under the field explaining them. */ ?>
		<p>
			<label for="arcade-max_games"><?php p($l->t('Games listed at most')); ?></label><br>
			<input type="range" id="arcade-max_games" class="arcade-setting arcade-range"
				data-setting="max_games"
				min="<?php p($limits['max_games']['min']); ?>"
				max="<?php p($limits['max_games']['max']); ?>" step="100"
				value="<?php p($defaults['max_games']); ?>">
			<output for="arcade-max_games"><?php p($defaults['max_games']); ?></output>
		</p>
		<p>
			<label for="arcade-max_depth"><?php p($l->t('Folders deep at most')); ?></label><br>
			<input type="range" id="arcade-max_depth" class="arcade-setting arcade-range"
				data-setting="max_depth"
				min="<?php p($limits['max_depth']['min']); ?>"
				max="<?php p($limits['max_depth']['max']); ?>" step="1"
				value="<?php p($defaults['max_depth']); ?>">
			<output for="arcade-max_depth"><?php p($defaults['max_depth']); ?></output>
		</p>
	</div>

	<?php /* Box art and checksums are a declarative
	        settings form now, rendered and saved by the server itself:
	        see \OCA\Arcade\Settings\DeclarativeAdmin. */ ?>

	<?php if ($systemFolder !== ''): ?>
	<div class="section" id="arcade-bios-section">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('BIOS'),
			'hint' => $l->t('A few consoles will not start without the firmware file of the real hardware. Copy these files from a console you own; they go into your System folder. Files added instance-wide with occ arcade:bios are offered to every player as a fallback.'),
		])); ?>
		<?php foreach ($systems as $systemId => $system): ?>
			<?php if ($system['bios'] === []) { continue; } ?>
			<div class="arcade-bios-system">
				<h3><?php p($system['label']); ?></h3>
				<?php /* Every row offers the same two controls, so the file
				        they belong to has to be part of what they are called;
				        a column of identical "Remove" buttons says nothing.
				        The state of the row is what both controls are for,
				        so it is their description. */ ?>
				<?php foreach ($system['bios'] as $name): ?>
					<?php $stateId = 'arcade-bios-state-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $name); ?>
					<p class="arcade-bios-file" data-name="<?php p($name); ?>">
						<code><?php p($name); ?></code>
						<em class="arcade-bios-state" id="<?php p($stateId); ?>"><?php p($l->t('Checking …')); ?></em>
						<input type="file" class="arcade-bios-input hidden"
							aria-label="<?php p($l->t('Upload %s', [$name])); ?>"
							aria-describedby="<?php p($stateId); ?>">
						<button type="button" class="arcade-bios-remove hidden"
							aria-label="<?php p($l->t('Remove %s', [$name])); ?>"
							aria-describedby="<?php p($stateId); ?>"><?php p($l->t('Remove')); ?></button>
					</p>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="section">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('Core options'),
			'hint' => $l->t('Options of the emulator cores themselves. Left on "Core default", the core decides.'),
		])); ?>
		<?php foreach ($coreOptions as $core => $options): ?>
			<?php
			// What an administrator came looking for is the system, not the
			// name of the core that runs it, so the systems are what the
			// row says first. How many options have been moved off "Core
			// default" is said too: without it, fourteen collapsed rows
			// look alike, and finding what somebody set means opening all
			// of them.
			$changed = count($settings['core_options'][$core] ?? []);
			?>
			<details class="arcade-core-options">
				<summary>
					<?php p(implode(', ', $systemsByCore[$core] ?? []) ?: $core); ?>
					<em><?php p($core); ?></em>
					<?php if ($changed > 0): ?>
						<span class="arcade-core-changed">
							<?php p($l->n('%n option changed', '%n options changed', $changed)); ?>
						</span>
					<?php endif; ?>
				</summary>
				<?php foreach ($options as $key => $option): ?>
					<p>
						<?php /* Not through $l->t(): these come from CoreOptions, so they are
						        not translatable anyway, and a "%" in them would be taken for
						        a format specifier. */ ?>
						<label for="arcade-option-<?php p($key); ?>"><?php p($option['label']); ?></label><br>
						<select id="arcade-option-<?php p($key); ?>" class="arcade-core-option"
							data-core="<?php p($core); ?>" data-option="<?php p($key); ?>">
							<option value=""><?php p($l->t('Core default')); ?></option>
							<?php foreach ($option['values'] as $value => $label): ?>
								<option value="<?php p($value); ?>"
									<?php if (($settings['core_options'][$core][$key] ?? '') === (string)$value) { p('selected'); } ?>>
									<?php p($label); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endforeach; ?>
				<p>
					<button type="button" class="arcade-core-reset"
						data-core="<?php p($core); ?>"><?php p($l->t('Reset this core to defaults')); ?></button>
				</p>
			</details>
		<?php endforeach; ?>
	</div>

	<?php /* These used to sit inside the core options, under whichever
	        core happened to run the system -- so choosing what a Game Boy
	        shows meant knowing it runs on gambatte and opening that. What
	        a game is pictured with has nothing to do with the emulator,
	        so it is asked by system, which is how it is thought about. */ ?>
	<div class="section">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('Pictures'),
			'hint' => $l->t('What a game of each system is shown with to begin with. Everybody can pick their own afterwards; which file each kind comes out of is in the usage guide.'),
		])); ?>
		<div class="arcade-pictures">
			<?php foreach ($systems as $systemId => $system): ?>
				<p>
					<label for="arcade-thumbnail-<?php p($systemId); ?>"><?php p($system['label']); ?></label><br>
					<select id="arcade-thumbnail-<?php p($systemId); ?>" class="arcade-thumbnail-type"
						data-system="<?php p($systemId); ?>">
						<?php foreach ($thumbnailTypes as $type => $label): ?>
							<option value="<?php p($type); ?>"
								<?php if (($storedTypes[$systemId] ?? 'boxart') === $type) { p('selected'); } ?>>
								<?php p($label); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endforeach; ?>
		</div>
	</div>
</div>
