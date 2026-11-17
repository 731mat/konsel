<?php

declare(strict_types=1);

use App\Console\Demo\DemoSeeder;
use App\Core\Security\Authenticator;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingStatus;
use App\Model\People\PeopleFacade;
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

	// vzor obce Neplachovice: podbody a čísla usnesení navazující od 19
	$past = $container->getByType(MeetingFacade::class)->find($municipalityId, status: MeetingStatus::Closed)[0];
	$numbers = array_map(static fn($r) => $r->getNumber(), $container->getByType(App\Model\Resolution\ResolutionFacade::class)->findByMeeting($past->getId()));
	Assert::same(['19/2/1', '19/2/2', '19/2/3', '20/2/1', '20/2/2', '21/2/1', '21/2/2', '22/2', '23/2/1', '23/2/2', '24/2', '25/2/1', '25/2/2', '26/2/1', '26/2/2', '26/2/3'], $numbers);
	$html = $container->getByType(App\Model\Document\MeetingDocumentFacade::class)->renderHtml($past, App\Model\Document\MeetingDocumentType::Resolutions);
	Assert::contains('<strong>19/2/1 – Schvaluje:</strong>', $html);
	Assert::contains('Účast: 8 členů zastupitelstva', $html);
});
