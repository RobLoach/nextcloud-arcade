<?php

declare(strict_types=1);

namespace OCA\Arcade\Activity;

use OCP\Activity\ISetting;
use OCP\IL10N;

/**
 * The switch in the Activity settings that mutes the app's entries.
 *
 * Its identifier is the type of every event the app publishes, which is
 * how turning it off silences them all at once. The stream is on by
 * default -- the entries are the player's own and visible only to them --
 * and mail is off, because nobody wants their own gaming mailed to them.
 */
class Setting implements ISetting {
	public function __construct(
		private IL10N $l,
	) {
	}

	public function getIdentifier(): string {
		return ActivityPublisher::TYPE;
	}

	public function getName(): string {
		return $this->l->t('Games you played in the Arcade');
	}

	public function getPriority(): int {
		return 60;
	}

	public function canChangeStream(): bool {
		return true;
	}

	public function isDefaultEnabledStream(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return true;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
