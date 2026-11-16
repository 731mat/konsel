<?php

declare(strict_types=1);

use App\Application\Municipality\Command\CreateMunicipality;
use App\Application\Municipality\MunicipalityFacade;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('create persists municipality with generated slug and timestamps', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$facade = $container->getByType(MunicipalityFacade::class);

	$municipality = $facade->create(new CreateMunicipality('Neplachovice', '00064581'));
	$container->getByType(EntityManagerInterface::class)->clear();
	$loaded = $container->getByType(MunicipalityRepository::class)->get($municipality->getId());

	Assert::same('Neplachovice', $loaded->getName());
	Assert::same('neplachovice', $loaded->getSlug());
	Assert::same('00064581', $loaded->getIco()?->value);
	Assert::same('2026-11-16 10:00:00', $loaded->getCreatedAt()->format('Y-m-d H:i:s'));
});

test('duplicate slug is rejected', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$facade = $container->getByType(MunicipalityFacade::class);
	$facade->create(new CreateMunicipality('Neplachovice'));

	$e = Assert::exception(
		fn() => $facade->create(new CreateMunicipality('NEPLACHOVICE')),
		ValidationException::class,
		'Obec se zkratkou „neplachovice“ už existuje.',
	);
	Assert::same('slug', $e?->field);
});

test('duplicate IČO is rejected', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$facade = $container->getByType(MunicipalityFacade::class);
	$facade->create(new CreateMunicipality('Obec A', '00064581'));

	Assert::exception(
		fn() => $facade->create(new CreateMunicipality('Obec B', '64581')),
		ValidationException::class,
	);
});
