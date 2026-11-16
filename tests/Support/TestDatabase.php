<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Nette\DI\Container;

/**
 * Připraví databázi integračního testu spuštěním všech migrací.
 */
final class TestDatabase
{
	public static function migrate(Container $container): void
	{
		$dependencyFactory = $container->getByType(DependencyFactory::class);
		$dependencyFactory->getMetadataStorage()->ensureInitialized();

		if ($dependencyFactory->getMigrationRepository()->getMigrations()->count() === 0) {
			return;
		}

		$version = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
		$plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($version);
		$dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
	}
}
