<?php

declare(strict_types=1);

namespace OCA\Arcade\Activity;

use OCA\Arcade\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Turns the app's stored activities into sentences.
 *
 * The stream keeps only the subject key and its raw parameters; every look
 * at it comes back through here, in the language of whoever is looking.
 * When the file id is known the game is a rich file object, so clients
 * render it as a link to the file; without one it is merely highlighted.
 */
class Provider implements IProvider {
	public const SUBJECT_GAME_STARTED = 'game_started';
	public const SUBJECT_SESSION_ENDED = 'session_ended';
	public const SUBJECT_STATE_SAVED = 'state_saved';

	public function __construct(
		private IFactory $languageFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID || $event->getType() !== ActivityPublisher::TYPE) {
			throw new UnknownActivityException('not an arcade activity');
		}

		$l = $this->languageFactory->get(Application::APP_ID, $language);
		$parameters = $event->getSubjectParameters();
		$game = $this->gameRichObject(
			is_array($parameters['game'] ?? null) ? $parameters['game'] : [],
		);

		switch ($event->getSubject()) {
			case self::SUBJECT_GAME_STARTED:
				$subject = $l->t('You played {game}');
				break;
			case self::SUBJECT_SESSION_ENDED:
				$subject = $l->t(
					'You played {game} for %1$s',
					[$this->duration($l, (int)($parameters['seconds'] ?? 0))],
				);
				break;
			case self::SUBJECT_STATE_SAVED:
				$subject = $l->t(
					'You saved {game} to slot %1$d',
					[(int)($parameters['slot'] ?? 0)],
				);
				break;
			default:
				throw new UnknownActivityException('unknown arcade subject ' . $event->getSubject());
		}

		$event->setIcon(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
			),
		);
		if (isset($game['link'])) {
			$event->setLink($game['link']);
		}
		$event->setParsedSubject(str_replace('{game}', $game['name'], $subject));
		$event->setRichSubject($subject, ['game' => $game]);
		return $event;
	}

	/**
	 * The game as a rich object: a file when the id is known, so it renders
	 * as a link to the file, and a plain highlight when it is not.
	 *
	 * @param array<array-key, mixed> $game
	 * @return array{type: string, id: string, name: string, path?: string, link?: string}
	 */
	private function gameRichObject(array $game): array {
		$path = is_string($game['path'] ?? null) ? $game['path'] : '';
		$name = is_string($game['name'] ?? null) && $game['name'] !== ''
			? $game['name']
			: basename($path);
		if (!isset($game['id']) || !is_numeric($game['id'])) {
			return ['type' => 'highlight', 'id' => $name, 'name' => $name];
		}
		$id = (string)(int)$game['id'];
		$rich = [
			'type' => 'file',
			'id' => $id,
			'name' => $name,
			// The definition wants the path without its leading slash.
			'path' => ltrim($path, '/'),
		];
		try {
			$rich['link'] = $this->urlGenerator->linkToRouteAbsolute(
				'files.viewcontroller.showFile',
				['fileid' => $id],
			);
		} catch (\Throwable) {
			// No Files route, no link; the name still reads fine.
		}
		return $rich;
	}

	/**
	 * A length of time in words, the way the library shows play time:
	 * whole minutes, with the hours in front once there are any.
	 */
	private function duration(IL10N $l, int $seconds): string {
		$hours = intdiv($seconds, 3600);
		$minutes = (int)round(($seconds % 3600) / 60);
		return $hours > 0
			? $l->t('%1$d h %2$d min', [$hours, $minutes])
			: $l->t('%1$d min', [$minutes]);
	}
}
