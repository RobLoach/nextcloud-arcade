<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Controller\PageController;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\RecentService;
use OCA\Arcade\Service\SettingsService;
use OCA\Files\Event\LoadSidebar;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
	private const GAMES = [
		[
			'id' => 1,
			'path' => '/Games/Mario.nes',
			'basename' => 'Mario.nes',
			'system' => 'nes',
			'size' => 40976,
			'mtime' => 100,
			'tags' => ['platformer'],
		],
		[
			'id' => 2,
			'path' => '/Games/Sonic.md',
			'basename' => 'Sonic.md',
			'system' => 'segaMD',
			'size' => 524288,
			'mtime' => 200,
			'tags' => [],
		],
	];

	/**
	 * @param list<array<string, mixed>> $games what the scan finds
	 * @param array<int, bool> $favoriteIds file id => true
	 * @param list<int> $recentIds file ids in play order
	 * @param array<int, array<string, int>> $stats play stats by file id
	 * @param string $ifNoneMatch the If-None-Match header of the request
	 * @param list<array{path: string, games: int, systems: list<string>}> $suggestions
	 */
	private function controller(
		array $games = self::GAMES,
		array $favoriteIds = [],
		array $recentIds = [],
		array $stats = [],
		string $ifNoneMatch = '',
		array $suggestions = [],
		?IEventDispatcher $eventDispatcher = null,
		?IInitialState $initialState = null,
		?Node $fileNode = null,
	): PageController {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $name === 'If-None-Match' ? $ifNoneMatch : '',
		);

		$settingsService = $this->createStub(SettingsService::class);
		$settingsService->method('getUserSettings')->willReturn([
			'library_folder' => '/Games',
			'max_games' => 5000,
		]);

		$libraryFolder = $this->createStub(Folder::class);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturn($libraryFolder);
		$userFolder->method('getFirstNodeById')->willReturn($fileNode);
		$userFolder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): ?string => str_starts_with($path, '/alice/files/')
				? substr($path, strlen('/alice/files'))
				: null,
		);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$libraryService = $this->createStub(LibraryService::class);
		$libraryService->method('getGames')->willReturn($games);
		$libraryService->method('filterGames')->willReturnArgument(0);
		$libraryService->method('suggestFolders')->willReturn($suggestions);
		$libraryService->method('thumbnailsVersion')->willReturn('etag-thumbs');

		$recentService = $this->createStub(RecentService::class);
		$recentService->method('get')->willReturn($recentIds);
		$recentService->method('favoriteIds')->willReturn($favoriteIds);
		$recentService->method('stats')->willReturn($stats);

		return new PageController(
			'arcade',
			$request,
			$initialState ?? $this->createStub(IInitialState::class),
			$settingsService,
			$libraryService,
			$recentService,
			$rootFolder,
			$this->createStub(IJobList::class),
			$eventDispatcher ?? $this->createStub(IEventDispatcher::class),
			'alice',
		);
	}

	/**
	 * @param array<string, mixed> $states filled with what index() provides
	 */
	private function capturingState(array &$states): IInitialState {
		$initialState = $this->createStub(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $value) use (&$states): void {
				$states[$key] = $value;
			},
		);
		return $initialState;
	}

	public function testIndexResolvesTheFileIdToTheRelativePath(): void {
		$node = $this->createStub(Node::class);
		$node->method('getPath')->willReturn('/alice/files/ROMs/Mario.zip');
		$states = [];

		$this->controller(
			initialState: $this->capturingState($states),
			fileNode: $node,
		)->index(fileId: 42);

		$this->assertSame('/ROMs/Mario.zip', $states['file']);
	}

	public function testIndexTreatsAnUnresolvableFileIdLikeNoFile(): void {
		$states = [];

		$this->controller(initialState: $this->capturingState($states))->index(fileId: 999);

		$this->assertSame('', $states['file']);
	}

	public function testIndexStillRespectsThePlainFileParameter(): void {
		$states = [];

		$this->controller(initialState: $this->capturingState($states))->index('/Games/Mario.nes');

		$this->assertSame('/Games/Mario.nes', $states['file']);
	}

	public function testIndexAsksForTheFilesSidebar(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->isInstanceOf(LoadSidebar::class));

		$response = $this->controller(eventDispatcher: $dispatcher)->index();

		$this->assertSame('index', $response->getTemplateName());
	}

	public function testEtagIsSetAndStableForIdenticalInputs(): void {
		$first = $this->controller()->library();
		$second = $this->controller()->library();

		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertNotEmpty($first->getETag());
		$this->assertSame($first->getETag(), $second->getETag());
	}

	public function testEtagChangesWhenFavoritesChange(): void {
		$plain = $this->controller()->library();
		$starred = $this->controller(self::GAMES, [1 => true])->library();

		$this->assertNotSame($plain->getETag(), $starred->getETag());
	}

	public function testEtagChangesWhenRecentChange(): void {
		$plain = $this->controller()->library();
		$played = $this->controller(self::GAMES, [], [2])->library();

		$this->assertNotSame($plain->getETag(), $played->getETag());
	}

	public function testEtagChangesWhenTagsChange(): void {
		$games = self::GAMES;
		$games[1]['tags'] = ['co-op'];

		$plain = $this->controller()->library();
		$tagged = $this->controller($games)->library();

		$this->assertNotSame($plain->getETag(), $tagged->getETag());
	}

	public function testEtagChangesWithTheQuery(): void {
		$onePage = $this->controller()->library();
		$another = $this->controller()->library(limit: 120);

		$this->assertNotSame($onePage->getETag(), $another->getETag());
	}

	public function testStatsOfTheLibraryAreInTheResponse(): void {
		$response = $this->controller(stats: [
			1 => ['seconds' => 600, 'plays' => 2, 'time' => 50],
			999 => ['seconds' => 60, 'plays' => 1, 'time' => 40],
		])->library();

		$this->assertSame(
			[1 => ['seconds' => 600, 'plays' => 2, 'time' => 50]],
			$response->getData()['stats'],
			'the stats of a game that is not in the library are left out',
		);
	}

	public function testTheThumbnailsVersionRidesAlongWithTheListing(): void {
		$response = $this->controller()->library();

		$this->assertSame(
			'etag-thumbs',
			$response->getData()['thumbnailsVersion'],
			'the browser versions its preview URLs with it, or a replaced image would show stale for a day',
		);
	}

	public function testEtagChangesWhenStatsChange(): void {
		$plain = $this->controller()->library();
		$played = $this->controller(stats: [1 => ['seconds' => 600, 'plays' => 1, 'time' => 50]])->library();

		$this->assertNotSame($plain->getETag(), $played->getETag());
	}

	public function testMatchingIfNoneMatchGetsNotModified(): void {
		$etag = $this->controller()->library()->getETag();

		$response = $this->controller(ifNoneMatch: '"' . $etag . '"')->library();

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		$this->assertSame($etag, $response->getETag());
		$this->assertSame([], $response->getData());
	}

	public function testSuggestionsAreWrappedForTheOnboardingPanel(): void {
		$suggestions = [
			['path' => '/ROMs', 'games' => 40, 'systems' => ['gb', 'snes']],
			['path' => '/Downloads/GB', 'games' => 2, 'systems' => ['gb']],
		];

		$response = $this->controller(suggestions: $suggestions)->suggest();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['suggestions' => $suggestions], $response->getData());
	}

	public function testNoSuggestionsIsAnEmptyList(): void {
		$response = $this->controller()->suggest();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['suggestions' => []], $response->getData());
	}

	public function testStaleIfNoneMatchGetsTheFullPayload(): void {
		$etag = $this->controller()->library()->getETag();

		$response = $this->controller(
			self::GAMES,
			[1 => true],
			ifNoneMatch: '"' . $etag . '"',
		)->library();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotSame($etag, $response->getETag());
		$this->assertNotEmpty($response->getData()['games']);
	}
}
