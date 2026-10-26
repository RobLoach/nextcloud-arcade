<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Saves are filed under the name of the core that played the game, the way
 * RetroArch files them. For most games the path says which core that is,
 * but for a zip only the player knows, once it has looked inside -- so the
 * core it ran is noted with the game, for the requests that come from
 * anywhere but the player: the Files sidebar, the library, and the cleanup
 * after a game is deleted.
 *
 * @psalm-suppress UnusedClass
 */
class Version0488Date20261025120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('arcade_games')) {
			return null;
		}
		$table = $schema->getTable('arcade_games');
		if ($table->hasColumn('core')) {
			return null;
		}
		// The libretro name of the core, such as "fceumm"; null when the
		// player has not said, and the path is asked instead. Nullable
		// rather than an empty default: Nextcloud refuses a column added
		// to a table that is not null with '' for a default, since Oracle
		// stores '' as null and the rows already there would break it.
		$table->addColumn('core', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'default' => null,
		]);
		return $schema;
	}
}
