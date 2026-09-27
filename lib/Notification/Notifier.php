<?php

declare(strict_types=1);

namespace OCA\Arcade\Notification;

use OCA\Arcade\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Puts the word of a finished box art run under the notification bell.
 *
 * The looking runs in the background, batch after batch, precisely so a
 * player can start it and walk away -- which means the settings page and
 * its polling are long gone when the run ends. This is the one place the
 * result still reaches them.
 *
 * @psalm-suppress UnusedClass
 */
class Notifier implements INotifier {
	/** A whole run of FetchThumbnails is over, however many batches it took. */
	public const SUBJECT_FETCH_FINISHED = 'fetch_finished';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Arcade');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID
			|| $notification->getSubject() !== self::SUBJECT_FETCH_FINISHED) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$found = (int)($notification->getSubjectParameters()['found'] ?? 0);
		$subject = $found > 0
			? str_replace('{found}', (string)$found, $l->t('Looked for box art: found {found}'))
			: $l->t('Looked for box art: none was found');

		return $notification
			->setParsedSubject($subject)
			// The pictures themselves are in the library, so that is where
			// the notification leads.
			->setLink($this->urlGenerator->linkToRouteAbsolute('arcade.page.index'))
			->setIcon($this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
			));
	}
}
