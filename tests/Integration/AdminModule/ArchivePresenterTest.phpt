<?php

declare(strict_types=1);

use App\Model\Document\DocumentArchiveFacade;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('archive lists finished meetings by year and body with final documents', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$meetingId = $scenario->meeting->meeting->getId();
	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->transition($meetingId, MeetingTransition::Close);
	$meetings->transition($meetingId, MeetingTransition::ApproveMinutes);
	[$minutes] = $container->getByType(DocumentArchiveFacade::class)->archiveFinal($meetingId, null);
	$planned = $scenario->meeting->another($container);

	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->meeting->municipality, role: Role::Reader));

	$html = $tester->render('Admin:Archive');
	Assert::contains('1 z 1 zasedání s ověřeným zápisem', $html);
	Assert::contains('Zastupitelstvo obce Neplachovice', $html);
	Assert::contains('1. zasedání', $html);
	Assert::contains("/admin/meeting/archived/{$meetingId}?documentId={$minutes->getId()}", $html);
	Assert::notContains('/admin/meeting/detail/' . $planned->meeting->getId(), $html, 'připravované zasedání v archivu není');

	Assert::contains('V tomto roce zatím neproběhlo žádné zasedání', $tester->render('Admin:Archive', 'default', [
		'year' => '2027',
	]));
});

test('closed meeting waits for minutes approval in the archive', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$container->getByType(MeetingFacade::class)->transition($scenario->meeting->meeting->getId(), MeetingTransition::Close);

	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->meeting->municipality));

	$html = $tester->render('Admin:Archive');
	Assert::contains('0 z 1 zasedání s ověřeným zápisem', $html);
	Assert::contains('čeká na ověření zápisu', $html);
	Assert::contains('zápis do', $html);
});
