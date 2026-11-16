<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

/**
 * Tabulky, které nespravuje ORM (metadata migrací, FTS indexy), se nesmí objevit ve schema diffu.
 * DBAL 4 předává název tabulky jako string.
 */
final class SchemaAssetsFilter
{
	private const string IgnoredPattern = '~^(doctrine_migrations$|search_index)~';

	public function __invoke(string $assetName): bool
	{
		return preg_match(self::IgnoredPattern, $assetName) !== 1;
	}
}
