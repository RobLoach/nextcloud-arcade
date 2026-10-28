<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>

<div id="arcade-maintenance-settings">
	<?php /* Housekeeping an administrator would otherwise do with occ: each
	        task says how things stand, filled in by the page once it has
	        asked, and has one button. The two that remove saves ask to be
	        pressed twice. */ ?>
	<div class="section" id="arcade-maintenance">
		<?php print_unescaped($this->inc('part.sectionhead', [
			'title' => $l->t('Maintenance'),
			'hint' => $l->t('Tasks that keep Arcade in order. Each can also be run with occ.'),
		])); ?>
		<?php foreach ([
			'database' => [$l->t('Database'), $l->t('Repair the database')],
			'filetypes' => [$l->t('ROM file types'), $l->t('Fix ROM file types')],
			'leftbehind' => [$l->t('Saves of deleted games and users'), $l->t('Look for them')],
			'appdata' => [$l->t('Saves left by earlier versions'), $l->t('Remove them')],
		] as $task => [$heading, $action]): ?>
			<div class="arcade-maintenance-task" data-task="<?php p($task); ?>">
				<h3><?php p($heading); ?></h3>
				<p class="arcade-maintenance-state" aria-live="polite"><?php p($l->t('Checking …')); ?></p>
				<button type="button" class="arcade-maintenance-action" disabled><?php p($action); ?></button>
			</div>
		<?php endforeach; ?>
		<?php /* Nothing to press: how the background jobs last went, which
		        otherwise only the log says. */ ?>
		<div class="arcade-maintenance-task" data-task="jobs">
			<h3><?php p($l->t('Background jobs')); ?></h3>
			<div class="arcade-maintenance-state" aria-live="polite"><?php p($l->t('Checking …')); ?></div>
		</div>
	</div>
</div>
