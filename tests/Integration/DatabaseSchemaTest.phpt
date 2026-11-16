<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\TestDatabase;

require __DIR__ . '/../bootstrap.php';

test('Doctrine mapping is valid', function (): void {
	$container = ContainerFactory::create();
	$validator = new SchemaValidator($container->getByType(EntityManagerInterface::class));

	Assert::same([], $validator->validateMapping());
});

test('migrations produce schema identical to entity mapping', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$validator = new SchemaValidator($container->getByType(EntityManagerInterface::class));

	Assert::same([], $validator->getUpdateSchemaList());
});

test('SQLite connection enforces foreign keys', function (): void {
	$connection = ContainerFactory::create()->getByType(Connection::class);

	Assert::equal(1, $connection->fetchOne('PRAGMA foreign_keys'));
});
