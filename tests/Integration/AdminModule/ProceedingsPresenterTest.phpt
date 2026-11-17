<?php

declare(strict_types=1);

use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('clerk records attendance and sees the quorum change; reader only looks', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$personIds = [];
	foreach (['Dvořák', 'Svobodová', 'Novotný'] as $lastName) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson('Člen', $lastName));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		$personIds[] = $person->getId();
	}

	$container->getByType(MeetingFacade::class)->transition($scenario->meeting->getId(), MeetingTransition::Share);
	$fixtures = new Fixtures($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($scenario->municipality, 'editor@obec.cz', Role::Editor));
	$params = [
		'id' => $scenario->meeting->getId(),
	];

	$html = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('Není usnášeníschopné', $html);
	Assert::contains('přítomno 0 z 5, chybí 3', $html, 'zastupitelstvo má 5 míst, i když jsou obsazená jen 3');

	foreach ($personIds as $personId) {
		PresenterTester::assertRedirect($tester->run('Admin:Proceedings', 'default', $params + [
			'do' => 'mark',
			'personId' => $personId,
		], post: [
			'status' => 'present',
		]));
	}

	$html = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('Usnášeníschopné', $html);
	Assert::contains('přítomno 3 z 5, potřeba 3', $html);

	$e = Assert::exception(fn() => $tester->run('Admin:Proceedings', 'default', $params + [
		'do' => 'mark',
		'personId' => $personIds[0],
	], post: [
		'status' => 'unknown',
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());

	$tester->loginAs($fixtures->user($scenario->municipality, 'ctenar@obec.cz', Role::Reader));
	$readOnly = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('Usnášeníschopné', $readOnly);
	Assert::notContains('name="status"', $readOnly);
	$e = Assert::exception(fn() => $tester->run('Admin:Proceedings', 'default', $params + [
		'do' => 'allPresent',
	], post: [
		'x' => '1',
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});
