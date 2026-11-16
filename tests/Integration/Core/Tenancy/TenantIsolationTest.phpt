<?php

declare(strict_types=1);

use App\Core\Tenancy\CurrentMunicipality;
use App\Model\People\Command\SavePerson;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('data of another municipality is invisible once current municipality is set', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$ours = $fixtures->municipality('Neplachovice');
	$theirs = $fixtures->municipality('Jiná obec');
	$facade = $container->getByType(PeopleFacade::class);
	$facade->createPerson($ours->getId(), new SavePerson('Jan', 'Náš'));
	$foreign = $facade->createPerson($theirs->getId(), new SavePerson('Cizí', 'Člověk'));
	$foreignId = $foreign->getId();
	$container->getByType(EntityManagerInterface::class)->clear();

	$container->getByType(CurrentMunicipality::class)->set($facade->getPerson(1)->getMunicipality());

	Assert::exception(fn() => $facade->getPerson($foreignId), NotFoundException::class);
	$all = $container->getByType(EntityManagerInterface::class)->createQuery('SELECT p FROM App\Model\People\Person p')->getResult();
	Assert::count(1, $all);
});
