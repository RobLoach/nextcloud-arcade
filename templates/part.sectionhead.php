<?php
/**
 * The head of a settings section: its name, the place its saves are
 * reported, and an optional line of explanation.
 *
 * The reporting span is the reason this is shared. Every section needs
 * one -- src/settings.js looks for the nearest .msg to the field that
 * changed -- and a section written without one saves silently, which
 * looks exactly like a section that did not save at all.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<h2 class="inlineblock"><?php p($_['title']); ?></h2>
<span class="msg" aria-live="polite"></span>
<?php if (($_['hint'] ?? '') !== ''): ?>
	<p class="settings-hint"><?php p($_['hint']); ?></p>
<?php endif; ?>
