<?php

declare(strict_types=1);

namespace OCA\Arcade\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A game a user has saves for: what its states are filed under, where the
 * ROM was last seen, and what it hashed to when the states were written.
 *
 * The key is the file id as a string for the games that have one, and the
 * sha256 of the path for the ones that never did; the file id is kept as a
 * number besides, zero for those, so a game can also be found by the id its
 * file had.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getGameKey()
 * @method void setGameKey(string $gameKey)
 * @method string getLastPath()
 * @method void setLastPath(string $lastPath)
 * @method string getChecksum()
 * @method void setChecksum(string $checksum)
 * @method ?string getCore()
 * @method void setCore(?string $core)
 */
class Game extends Entity {
	protected string $userId = '';
	protected int $fileId = 0;
	protected string $gameKey = '';
	protected string $lastPath = '';
	protected string $checksum = '';
	protected ?string $core = null;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('fileId', 'integer');
		$this->addType('gameKey', 'string');
		$this->addType('lastPath', 'string');
		$this->addType('checksum', 'string');
		$this->addType('core', 'string');
	}
}
