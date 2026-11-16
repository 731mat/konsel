<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

/**
 * Tabulky, které nespravuje ORM ani migrace přes Schema (FTS indexy), se nesmí objevit ve schema diffu.
 * doctrine_migrations zde být NESMÍ – metadata storage migrací ji pak nevidí (řeší MigrationsTableSchemaSubscriber).
 * DBAL 4 předává název tabulky jako string.
 */
final class SchemaAssetsFilter
{
	private const string IgnoredPattern = '~^search_index~';

	public function __invoke(string $assetName): bool
	{
		return preg_match(self::IgnoredPattern, $assetName) !== 1;
	}
}
