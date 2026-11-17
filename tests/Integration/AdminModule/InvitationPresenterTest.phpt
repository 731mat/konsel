<?php

declare(strict_types=1);

use App\Model\Mail\Mailer;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingStatus;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\FakeMailer;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('one click shares the draft meeting, e-mails invitations and shows links that could not be sent', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	foreach ([['Jan', 'Dvořák', 'dvorak@obec.cz'], ['Marie', 'Svobodová', 'svobodova@obec.cz'], ['Petr', 'Bezmailu', null]] as [$first, $last, $email]) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson($first, $last, email: $email));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
	}

	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality, role: Role::Editor));
	$params = [
		'id' => $scenario->meeting->getId(),
	];
	$mailer = $container->getByType(Mailer::class);
	assert($mailer instanceof FakeMailer);

	Assert::contains('Sdílet a rozeslat pozvánky', $tester->render('Admin:Meeting', 'links', $params));

	$html = PresenterTester::renderResponse($tester->run('Admin:Meeting', 'links', $params + [
		'do' => 'sendInvitations',
	], post: [
		'x' => '1',
	]));

	Assert::same(MeetingStatus::Shared, $container->getByType(MeetingFacade::class)->get($scenario->meeting->getId())->getStatus());
	Assert::count(2, $mailer->sent);
	Assert::contains('Pozvánky byly odeslány 2 členům.', $html);
	Assert::contains('Bez e-mailu: Petr Bezmailu', $html);
	Assert::contains('Tyto odkazy e-mailem neodešly', $html);
	Assert::match('%A%/odkaz/%A%', $html);

	$page = $tester->render('Admin:Meeting', 'links', $params);
	Assert::contains('Odeslané e-maily', $page);
	Assert::contains('svobodova@obec.cz', $page);
	Assert::contains('Rozeslat pozvánky e-mailem', $page, 'sdílené zasedání – lze poslat znovu');
});
