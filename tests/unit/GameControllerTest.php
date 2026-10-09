<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Controller\GameController;
use OCA\Arcade\Service\RecentService;
use OCA\Arcade\Service\StateService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class GameControllerTest extends TestCase {
	private const FILE_ID = 42;

	/**
	 * @param array<string, string> $metadata what is filed for the file
	 * @param array<int, array<string, int>> $stats play stats by file id
	 * @param list<array{slot: int, size: int, mtime: int, hasThumbnail: bool}> $states
	 */
	private function controller(
		?File $node,
		array $metadata = [],
		array $stats = [],
		array $states = [],
		?string $userId = 'alice',
	): GameController {
		$userFolder = $this->createStub(Folder::class);
		if ($node === null) {
			$userFolder->method('get')->willThrowException(new NotFoundException());
		} else {
			$userFolder->method('get')->willReturn($node);
		}
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$metadataManager = $this->createStub(IFilesMetadataManager::class);
		if ($metadata === []) {
			$metadataManager->method('getMetadata')
				->willThrowException(new \Exception('nothing filed'));
		} else {
			$filed = $this->createStub(IFilesMetadata::class);
			$filed->method('hasKey')->willReturnCallback(
				static fn (string $key): bool => isset($metadata[$key]),
			);
			$filed->method('getString')->willReturnCallback(
				static fn (string $key): string => $metadata[$key] ?? '',
			);
			$metadataManager->method('getMetadata')->willReturn($filed);
		}

		$recentService = $this->createStub(RecentService::class);
		$recentService->method('stats')->willReturn($stats);
		$stateService = $this->createStub(StateService::class);
		$stateService->method('list')->willReturn($states);

		return new GameController(
			'arcade',
			$this->createStub(IRequest::class),
			$rootFolder,
			$metadataManager,
			$recentService,
			$stateService,
			$userId,
		);
	}

	private function rom(string $path = '/alice/files/Games/Mario.nes'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn(self::FILE_ID);
		$node->method('getPath')->willReturn($path);
		return $node;
	}

	public function testDescribesAGameFromItsMetadata(): void {
		$states = [['slot' => 1, 'size' => 100, 'mtime' => 1700000000, 'hasThumbnail' => true]];
		$controller = $this->controller(
			$this->rom(),
			[
				'arcade-system' => 'nes',
				'arcade-title' => 'SUPER MARIO',
				'arcade-region' => 'Japan, USA',
				'arcade-mapper' => 'MMC3',
				'arcade-md5' => '811b027eaf99c2def7b933c5208636de',
				'arcade-crc32' => 'cbf43926',
			],
			[self::FILE_ID => ['plays' => 3, 'seconds' => 5400, 'time' => 1700000100]],
			$states,
		);

		$response = $controller->game('/Games/Mario.nes');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			// What it is called on screen, which is not the name of the
			// folder its saves go in -- see CoreMap::displayName().
			'system' => ['id' => 'nes', 'name' => 'NES'],
			'title' => 'SUPER MARIO',
			'region' => 'Japan, USA',
			'mapper' => 'MMC3',
			'checksum' => '811b027e',
			'crc32' => 'cbf43926',
			'playtime' => ['plays' => 3, 'seconds' => 5400, 'time' => 1700000100],
			'states' => $states,
		], $response->getData());
	}

	public function testFallsBackToThePathWhenNothingIsFiled(): void {
		$controller = $this->controller($this->rom('/alice/files/Games/Sonic.md'));

		$data = $controller->game('/Games/Sonic.md')->getData();

		$this->assertSame('genesis', $data['system']['id']);
		$this->assertSame('Genesis', $data['system']['name']);
		$this->assertSame('', $data['title']);
		$this->assertSame('', $data['checksum']);
		$this->assertSame('', $data['crc32']);
	}

	public function testANeverPlayedGameStillHasThePlaytimeShape(): void {
		$data = $this->controller($this->rom())->game('/Games/Mario.nes')->getData();

		$this->assertSame(['plays' => 0, 'seconds' => 0, 'time' => 0], $data['playtime']);
		$this->assertSame([], $data['states']);
	}

	public function testANonRomFileIsNotFound(): void {
		$response = $this->controller($this->rom('/alice/files/notes.txt'))->game('/notes.txt');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAMissingFileIsNotFound(): void {
		$response = $this->controller(null)->game('/Games/Gone.nes');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAFolderIsNotFound(): void {
		$folder = $this->createStub(Folder::class);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturn($folder);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$controller = new GameController(
			'arcade',
			$this->createStub(IRequest::class),
			$rootFolder,
			$this->createStub(IFilesMetadataManager::class),
			$this->createStub(RecentService::class),
			$this->createStub(StateService::class),
			'alice',
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->game('/Games')->getStatus());
	}

	public function testWithoutAUserNothingIsAnswered(): void {
		$response = $this->controller($this->rom(), userId: null)->game('/Games/Mario.nes');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAnEmptyPathIsNotFound(): void {
		$response = $this->controller($this->rom())->game('');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
