<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * Regrese: přestavba tabulky municipality na SQLite (Version20261116160656) nesmí přes
 * ON DELETE CASCADE smazat uživatele.
 */
test('rebuilding parent table on SQLite keeps child rows', function (): void {
	$container = ContainerFactory::create();
	$connection = $container->getByType(Connection::class);
	TestDatabase::migrate($container, 'App\Migrations\Version20261116160655');

	$connection->insert('municipality', [
		'name' => 'Neplachovice',
		'slug' => 'neplachovice',
		'official_name' => 'Obec Neplachovice',
		'created_at' => '2026-11-16 10:00:00',
		'updated_at' => '2026-11-16 10:00:00',
	]);
	$connection->insert('app_user', [
		'municipality_id' => 1,
		'email' => 'a@b.cz',
		'password_hash' => 'x',
		'name' => 'A',
		'role' => 'admin',
		'active' => 1,
		'must_change_password' => 0,
		'created_at' => '2026-11-16 10:00:00',
		'updated_at' => '2026-11-16 10:00:00',
	]);

	TestDatabase::migrate($container);

	Assert::equal(1, $connection->fetchOne('SELECT COUNT(*) FROM app_user'));
	Assert::equal(1, $connection->fetchOne('PRAGMA foreign_keys'));
});
