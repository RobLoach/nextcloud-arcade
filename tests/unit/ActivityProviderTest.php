<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Activity\ActivityPublisher;
use OCA\Arcade\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How the stored activities read once the provider has put them into words.
 */
class ActivityProviderTest extends TestCase {
	private const GAME = ['id' => 101, 'name' => 'Mario.nes', 'path' => '/Games/Mario.nes'];

	private function provider(): Provider {
		// English reads back exactly what went in, parameters filled.
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string
				=> vsprintf($text, is_array($parameters) ? $parameters : [$parameters]),
		);
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l);

		$url = $this->createStub(IURLGenerator::class);
		$url->method('imagePath')->willReturnCallback(
			static fn (string $app, string $image): string => "/apps/$app/img/$image",
		);
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example' . $path,
		);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/index.php/f/101');

		return new Provider($factory, $url);
	}

	/**
	 * @param array<string, mixed> $parameters
	 */
	private function event(string $subject, array $parameters, string $app = 'arcade'): IEvent&MockObject {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn($app);
		$event->method('getType')->willReturn(ActivityPublisher::TYPE);
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setIcon', 'setLink', 'setParsedSubject', 'setRichSubject'] as $setter) {
			$event->method($setter)->willReturnSelf();
		}
		return $event;
	}

	public function testAnotherAppsEventIsNotOurs(): void {
		$event = $this->event(Provider::SUBJECT_GAME_STARTED, ['game' => self::GAME], app: 'files');
		$this->expectException(UnknownActivityException::class);
		$this->provider()->parse('en', $event);
	}

	public function testAnUnknownSubjectIsRefused(): void {
		$event = $this->event('high_score', ['game' => self::GAME]);
		$this->expectException(UnknownActivityException::class);
		$this->provider()->parse('en', $event);
	}

	public function testALaunchReadsAsPlayed(): void {
		$event = $this->event(Provider::SUBJECT_GAME_STARTED, ['game' => self::GAME]);
		$event->expects($this->once())->method('setParsedSubject')
			->with('You played Mario.nes')->willReturnSelf();
		$event->expects($this->once())->method('setRichSubject')
			->with('You played {game}', [
				'game' => [
					'type' => 'file',
					'id' => '101',
					'name' => 'Mario.nes',
					// The rich file object carries the path without its
					// leading slash, as the definition asks.
					'path' => 'Games/Mario.nes',
					'link' => 'https://cloud.example/index.php/f/101',
				],
			])->willReturnSelf();
		$event->expects($this->once())->method('setIcon')
			->with('https://cloud.example/apps/arcade/img/app-dark.svg')->willReturnSelf();
		$event->expects($this->once())->method('setLink')
			->with('https://cloud.example/index.php/f/101')->willReturnSelf();

		$this->provider()->parse('en', $event);
	}

	public function testASessionReadsWithItsLengthInWords(): void {
		$event = $this->event(
			Provider::SUBJECT_SESSION_ENDED,
			['game' => self::GAME, 'seconds' => 3900],
		);
		$event->expects($this->once())->method('setParsedSubject')
			->with('You played Mario.nes for 1 h 5 min')->willReturnSelf();

		$this->provider()->parse('en', $event);
	}

	public function testAnHourlessSessionReadsInMinutesAlone(): void {
		$event = $this->event(
			Provider::SUBJECT_SESSION_ENDED,
			['game' => self::GAME, 'seconds' => 300],
		);
		$event->expects($this->once())->method('setParsedSubject')
			->with('You played Mario.nes for 5 min')->willReturnSelf();

		$this->provider()->parse('en', $event);
	}

	public function testASaveReadsWithItsSlot(): void {
		$event = $this->event(
			Provider::SUBJECT_STATE_SAVED,
			['game' => self::GAME, 'slot' => 2],
		);
		$event->expects($this->once())->method('setParsedSubject')
			->with('You saved Mario.nes to slot 2')->willReturnSelf();

		$this->provider()->parse('en', $event);
	}

	public function testWithoutAFileIdTheGameIsMerelyHighlighted(): void {
		$event = $this->event(Provider::SUBJECT_GAME_STARTED, [
			'game' => ['name' => 'Mario.nes', 'path' => '/Games/Mario.nes'],
		]);
		$event->expects($this->once())->method('setParsedSubject')
			->with('You played Mario.nes')->willReturnSelf();
		$event->expects($this->once())->method('setRichSubject')
			->with('You played {game}', [
				'game' => ['type' => 'highlight', 'id' => 'Mario.nes', 'name' => 'Mario.nes'],
			])->willReturnSelf();
		// There is no file to point at.
		$event->expects($this->never())->method('setLink');

		$this->provider()->parse('en', $event);
	}
}
