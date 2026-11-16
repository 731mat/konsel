<?php

declare(strict_types=1);

use App\Model\Municipality\Municipality;
use App\Model\Municipality\MunicipalityRepository;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use Doctrine\ORM\EntityManagerInterface;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('domain exception rolls back, keeps entity manager open and reverts in-memory changes', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality('Neplachovice');
	$transaction = $container->getByType(TransactionManager::class);
	$repository = $container->getByType(MunicipalityRepository::class);

	Assert::exception(function () use ($transaction, $municipality, $repository): void {
		$transaction->run(function () use ($municipality, $repository): void {
			$municipality->rename('Přejmenováno');
			$repository->add(new Municipality('Nová', 'nova'));

			throw new ValidationException('Chyba validace');
		});
	}, ValidationException::class);

	$entityManager = $container->getByType(EntityManagerInterface::class);
	Assert::true($entityManager->isOpen());
	Assert::same('Neplachovice', $municipality->getName());
	Assert::null($repository->findBySlug('nova'));

	$transaction->run(fn() => $municipality->rename('Po chybě'));
	$entityManager->clear();
	Assert::same('Po chybě', $repository->get($municipality->getId())->getName());
});

test('unexpected exception closes entity manager', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$transaction = $container->getByType(TransactionManager::class);

	Assert::exception(fn() => $transaction->run(fn() => throw new RuntimeException('boom')), RuntimeException::class);
	Assert::false($container->getByType(EntityManagerInterface::class)->isOpen());
});
