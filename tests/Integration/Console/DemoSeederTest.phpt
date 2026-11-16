<?php

declare(strict_types=1);

use App\Application\Meeting\MeetingFacade;
use App\Application\People\PeopleFacade;
use App\Console\Demo\DemoSeeder;
use App\Core\Security\Authenticator;
use App\Domain\Meeting\MeetingStatus;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * Seeder musí vždy projít na prázdné DB – hlídá, že s novými funkcemi nezastará.
 */
test('demo seeder fills empty database with usable data', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);

	$summary = $container->getByType(DemoSeeder::class)->seed();

	Assert::true(count($summary) >= 3);
	$identity = $container->getByType(Authenticator::class)->authenticate('spravce@demo.konsel.test', DemoSeeder::Password);
	$municipalityId = $identity->getData()['municipalityId'];
	assert(is_int($municipalityId));
	$people = $container->getByType(PeopleFacade::class);
	$term = $people->findCurrentTerm($municipalityId);
	Assert::notNull($term);
	assert($term !== null);
	Assert::same([], $people->getStructureWarnings($term->getId()));

	$statuses = array_map(static fn($m) => $m->getStatus(), $container->getByType(MeetingFacade::class)->find($municipalityId));
	Assert::same([MeetingStatus::Draft, MeetingStatus::Shared, MeetingStatus::Closed, MeetingStatus::MinutesApproved], $statuses);
});
