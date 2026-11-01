<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Controller\SettingsController;
use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\LibraryScanner;
use OCA\Arcade\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The settings say what is wrong with a folder: that it is not there,
 * is a file, cannot be read, or is a path the settings refuse.
 */
class FolderCheckTest extends TestCase {
	/**
	 * A user folder holding /Games, a file at /notes.txt, an unreadable
	 * /Locked, a /Denied that refuses to be looked at, and an /Offline
	 * whose storage cannot be reached.
	 */
	private function rootFolder(): IRootFolder {
		$games = $this->createStub(Folder::class);
		$games->method('isReadable')->willReturn(true);
		$locked = $this->createStub(Folder::class);
		$locked->method('isReadable')->willReturn(false);
		$file = $this->createStub(File::class);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($games, $locked, $file) {
				return match ($path) {
					'Games', 'Games/NES' => $games,
					'Locked' => $locked,
					'notes.txt' => $file,
					'Denied' => throw new NotPermittedException(),
					'Offline' => throw new StorageNotAvailableException(),
					default => throw new NotFoundException(),
				};
			},
		);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		return $root;
	}

	public static function paths(): array {
		return [
			'an existing folder' => ['/Games', Folders::OK],
			'one written loosely' => [' Games/NES/ ', Folders::OK],
			'nothing set' => ['', Folders::OK],
			'only a slash' => ['/', Folders::OK],
			'a folder that is not there' => ['/Roms', Folders::MISSING],
			'a file' => ['/notes.txt', Folders::NOT_A_FOLDER],
			'a folder that cannot be read' => ['/Locked', Folders::UNREADABLE],
			'a folder not permitted' => ['/Denied', Folders::UNREADABLE],
			'a storage that is away' => ['/Offline', Folders::UNAVAILABLE],
			'a way out of the user folder' => ['/../bob/files', Folders::INVALID],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('paths')]
	public function testCheck(string $path, string $expected): void {
		$this->assertSame($expected, Folders::check($this->rootFolder(), 'alice', $path));
	}

	public function testAUserFolderThatCannotBeOpenedIsUnavailable(): void {
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willThrowException(new \RuntimeException('no'));
		$this->assertSame(Folders::UNAVAILABLE, Folders::check($root, 'alice', '/Games'));
	}

	private function controller(array $params, ?string $userId = 'alice'): SettingsController {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		return new SettingsController(
			'arcade',
			$request,
			$this->createStub(SettingsService::class),
			$this->createStub(LibraryScanner::class),
			$this->rootFolder(),
			$userId,
		);
	}

	public function testTheEndpointAnswersForTheFoldersAskedAbout(): void {
		$response = $this->controller([
			'library_folder' => '/Games',
			'saves_folder' => '/Saves',
			'thumbnails_folder' => '',
			'screenshots_folder' => '/notes.txt',
			// Not a folder setting, so not looked at.
			'volume' => '/Games',
		])->checkFolders();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'library_folder' => Folders::OK,
			'thumbnails_folder' => Folders::OK,
			'screenshots_folder' => Folders::NOT_A_FOLDER,
			'saves_folder' => Folders::MISSING,
		], $response->getData());
	}

	public function testTheEndpointWantsAUser(): void {
		$response = $this->controller(['library_folder' => '/Games'], null)->checkFolders();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}
}
