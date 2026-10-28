<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * RetroArch names the saves of a game in an archive after the file in it,
 * and so does the player -- comp.zip#Game (USA).sfc saves as
 * "Game (USA).srm". Only the player, which looked inside, knows that name,
 * so it is noted with the game for the requests that come from anywhere
 * else: the Files sidebar, the library, and the cleanup.
 *
 * @psalm-suppress UnusedClass
 */
class Version0489Date20261026120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('arcade_games')) {
			return null;
		}
		$table = $schema->getTable('arcade_games');
		if ($table->hasColumn('name')) {
			return null;
		}
		// Nullable, as a column added to a table that already has rows
		// must be: Nextcloud refuses a not-null one with '' for a default.
		$table->addColumn('name', Types::STRING, [
			'notnull' => false,
			'length' => 255,
			'default' => null,
		]);
		return $schema;
	}
}
