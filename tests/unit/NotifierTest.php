<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The notifier that words a finished box art run: what it says for its own
 * subjects, and that it keeps its hands off everyone else's.
 */
class NotifierTest extends TestCase {
	private function notifier(): Notifier {
		$l10n = $this->createStub(IL10N::class);
		// The English text as-is, which is what an untranslated server shows.
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text,
		);
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/arcade/');
		$urlGenerator->method('imagePath')->willReturn('/apps/arcade/img/app-dark.svg');
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $url): string => 'https://cloud.example' . $url,
		);

		return new Notifier($factory, $urlGenerator);
	}

	/**
	 * @param array<string, mixed> $parameters
	 */
	private function notification(string $app, string $subject, array $parameters = []): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setParsedSubject', 'setLink', 'setIcon'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		return $notification;
	}

	public function testAFinishedRunIsWordedWithItsTally(): void {
		$notification = $this->notification('arcade', Notifier::SUBJECT_FETCH_FINISHED, ['found' => 12]);
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('Looked for box art: found 12');
		$notification->expects($this->once())
			->method('setLink')
			->with('https://cloud.example/apps/arcade/');

		$this->notifier()->prepare($notification, 'en');
	}

	public function testARunThatFoundNothingSaysThatInstead(): void {
		$notification = $this->notification('arcade', Notifier::SUBJECT_FETCH_FINISHED, ['found' => 0]);
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('Looked for box art: none was found');

		$this->notifier()->prepare($notification, 'en');
	}

	public function testAnUnknownSubjectIsLeftToWhoeverKnowsIt(): void {
		$notification = $this->notification('arcade', 'something_else');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare($notification, 'en');
	}

	public function testAnotherAppsNotificationIsNotTouched(): void {
		$notification = $this->notification('files', Notifier::SUBJECT_FETCH_FINISHED, ['found' => 3]);

		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare($notification, 'en');
	}
}
