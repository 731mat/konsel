<?php

declare(strict_types=1);

use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingStatus;
use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SaveBody;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

final class MeetingScene
{
	public function __construct(
		public PresenterTester $tester,
		public Fixtures $fixtures,
		public MeetingFacade $meetings,
		public PeopleFacade $people,
		public Municipality $municipality,
		public Body $council,
	) {}

	public static function boot(Role $role = Role::Admin): self
	{
		$container = ContainerFactory::create();
		TestDatabase::migrate($container);
		$fixtures = new Fixtures($container);
		$municipality = $fixtures->municipality();
		$people = $container->getByType(PeopleFacade::class);
		$term = $people->createTerm($municipality->getId(), '2026–2030', new DateTimeImmutable('2026-10-03'));
		$council = $people->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo obce Neplachovice', 5));
		foreach (['Dvořák', 'Svobodová', 'Novotný'] as $i => $lastName) {
			$person = $people->createPerson($municipality->getId(), new SavePerson('Člen', $lastName));
			$people->addMember($council->getId(), new AddMember($person->getId(), $i === 0 ? MemberFunction::Mayor : MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		}

		$tester = new PresenterTester($container);
		$tester->loginAs($fixtures->user($municipality, role: $role));

		return new self($tester, $fixtures, $container->getByType(MeetingFacade::class), $people, $municipality, $council);
	}

	public function meeting(string $scheduledAt = '2026-12-09 18:00'): int
	{
		return $this->meetings->create($this->council->getId(), new SaveMeeting(MeetingType::Regular, new DateTimeImmutable($scheduledAt), 'Obecní dům'))->getId();
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function signal(int $meetingId, string $signal, array $params = []): Nette\Application\Response
	{
		return $this->tester->run('Admin:Meeting', 'detail', [
			'id' => $meetingId,
			'do' => $signal,
		] + $params, post: [
			'confirm' => '1',
		]);
	}
}

test('admin creates a meeting through the form and walks it through the whole lifecycle', function (): void {
	$scene = MeetingScene::boot();

	$redirect = PresenterTester::assertRedirect($scene->tester->submitForm('Admin:Meeting', 'meetingForm', [
		'body' => (string) $scene->council->getId(),
		'type' => 'regular',
		'scheduledAt' => '2026-12-09T18:00',
		'place' => 'Obecní dům',
		'number' => '',
	], action: 'add'));
	$meeting = $scene->meetings->find($scene->municipality->getId())[0];
	Assert::contains("/admin/meeting/detail/{$meeting->getId()}", $redirect->getUrl());
	Assert::same(1, $meeting->getNumber());

	$html = $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meeting->getId(),
	]);
	Assert::contains('1. zasedání', $html);
	Assert::contains('Sdílet podklady', $html);
	Assert::contains('Program zasedání zatím není sestavený.', $html);

	foreach ([
		'share' => MeetingStatus::Shared,
		'start' => MeetingStatus::InProgress,
		'close' => MeetingStatus::Closed,
		'approve_minutes' => MeetingStatus::MinutesApproved,
	] as $transition => $status) {
		PresenterTester::assertRedirect($scene->signal($meeting->getId(), 'transition', [
			'transition' => $transition,
		]));
		Assert::same($status, $scene->meetings->get($meeting->getId())->getStatus(), $transition);
	}

	$html = $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meeting->getId(),
		'tab' => 'documents',
	]);
	Assert::contains('Zápis ověřen', $html);
	Assert::notContains('Upravit</a>', $html);
	Assert::contains('Podklady k zasedání', $html);
});

test('officials form validates domain rules and saves', function (): void {
	$scene = MeetingScene::boot();
	$meetingId = $scene->meeting();
	[$mayor, $second, $third] = array_values(array_filter(
		$scene->people->findPersons($scene->municipality->getId()),
		static fn($p) => true,
	));

	$html = PresenterTester::renderResponse($scene->tester->submitForm('Admin:Meeting', 'officialsForm', [
		'chairperson' => (string) $mayor->getId(),
		'firstVerifier' => (string) $second->getId(),
		'secondVerifier' => (string) $second->getId(),
		'recorder' => '',
	], action: 'officials', params: [
		'id' => $meetingId,
	]));
	Assert::contains('Ověřovatelé musí být dvě různé osoby.', $html);

	PresenterTester::assertRedirect($scene->tester->submitForm('Admin:Meeting', 'officialsForm', [
		'chairperson' => (string) $mayor->getId(),
		'firstVerifier' => (string) $second->getId(),
		'secondVerifier' => (string) $third->getId(),
		'recorder' => (string) $third->getId(),
	], action: 'officials', params: [
		'id' => $meetingId,
	]));
	Assert::count(2, $scene->meetings->get($meetingId)->getVerifiers());
});

test('late sharing shows notice period warning', function (): void {
	$scene = MeetingScene::boot();
	$meetingId = $scene->meeting('2026-11-19 18:00');

	Assert::contains('§ 93 odst. 1 zákona o obcích', $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
	]));
	PresenterTester::assertRedirect($scene->signal($meetingId, 'transition', [
		'transition' => 'share',
	]));
	Assert::same(MeetingStatus::Shared, $scene->meetings->get($meetingId)->getStatus());
});

test('list filter by body, year and status', function (): void {
	$scene = MeetingScene::boot();
	$scene->meeting('2026-12-09 18:00');
	$january = $scene->meeting('2027-01-20 18:00');

	$html = $scene->tester->render('Admin:Meeting', 'default', [
		'year' => '2027',
		'body' => (string) $scene->council->getId(),
		'status' => '',
	]);
	Assert::contains("/admin/meeting/detail/{$january}", $html);
	Assert::notContains('9. 12. 2026', $html);

	Assert::contains('Filtru neodpovídá žádné zasedání.', $scene->tester->render('Admin:Meeting', 'default', [
		'status' => 'closed',
	]));
	Assert::contains('Připravovaná zasedání', $scene->tester->render('Admin:Dashboard'));
});

test('editor cannot reopen or delete, admin can', function (): void {
	$scene = MeetingScene::boot(Role::Editor);
	$meetingId = $scene->meeting();
	foreach (['share', 'start', 'close'] as $transition) {
		$scene->signal($meetingId, 'transition', [
			'transition' => $transition,
		]);
	}

	$e = Assert::exception(fn() => $scene->tester->run('Admin:Meeting', 'reopen', [
		'id' => $meetingId,
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
	Assert::notContains('Znovu otevřít', $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
	]));
	$e = Assert::exception(fn() => $scene->signal($meetingId, 'transition', [
		'transition' => 'reopen',
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
	Assert::same(MeetingStatus::Closed, $scene->meetings->get($meetingId)->getStatus());

	$admin = $scene->fixtures->user($scene->municipality, 'spravce@neplachovice.cz', Role::Admin);
	$scene->tester->loginAs($admin);
	Assert::contains('Znovu otevřít', $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
	]));
	Assert::contains('Důvod znovuotevření', $scene->tester->render('Admin:Meeting', 'reopen', [
		'id' => $meetingId,
	]));
	$response = $scene->tester->submitForm('Admin:Meeting', 'reopenForm', [
		'reason' => 'krátký',
	], 'reopen', params: [
		'id' => $meetingId,
	]);
	Assert::type(Nette\Application\Responses\TextResponse::class, $response, 'krátký důvod neprojde');
	Assert::same(MeetingStatus::Closed, $scene->meetings->get($meetingId)->getStatus());

	PresenterTester::assertRedirect($scene->tester->submitForm('Admin:Meeting', 'reopenForm', [
		'reason' => 'Omylem ukončené, chybí hlasování o bodu Různé.',
	], 'reopen', params: [
		'id' => $meetingId,
	]));
	Assert::same(MeetingStatus::InProgress, $scene->meetings->get($meetingId)->getStatus());
	$html = $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
	]);
	Assert::contains('Historie znovuotevření', $html);
	Assert::contains('Omylem ukončené, chybí hlasování o bodu Různé.', $html);
	Assert::contains('Ukončeno → Probíhá', $html);
});

test('reader sees meetings but cannot change them', function (): void {
	$scene = MeetingScene::boot(Role::Reader);
	$meetingId = $scene->meeting();

	$html = $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
	]);
	Assert::notContains('Sdílet podklady', $html);
	Assert::notContains('Nové zasedání', $scene->tester->render('Admin:Meeting'));

	foreach (['add', 'edit', 'officials'] as $action) {
		$e = Assert::exception(fn() => $scene->tester->run('Admin:Meeting', $action, [
			'id' => $meetingId,
		]), BadRequestException::class);
		Assert::same(403, $e?->getHttpCode(), $action);
	}

	$e = Assert::exception(fn() => $scene->signal($meetingId, 'transition', [
		'transition' => 'share',
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});

test('draft meeting can be deleted by admin', function (): void {
	$scene = MeetingScene::boot();
	$meetingId = $scene->meeting();

	PresenterTester::assertRedirect($scene->signal($meetingId, 'delete'));
	Assert::same([], $scene->meetings->find($scene->municipality->getId()));
});

test('meeting of another municipality returns 404', function (): void {
	$scene = MeetingScene::boot();
	$other = $scene->fixtures->municipality('Cizí obec');
	$term = $scene->people->createTerm($other->getId(), '2026–2030', new DateTimeImmutable('2026-10-03'));
	$body = $scene->people->createBody($term->getId(), new SaveBody(BodyType::Council, 'Cizí zastupitelstvo', 5));
	$foreign = $scene->meetings->create($body->getId(), new SaveMeeting(MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Jinde'));

	$scene->tester->render('Admin:Dashboard');
	foreach (['detail', 'edit', 'officials'] as $action) {
		$e = Assert::exception(fn() => $scene->tester->run('Admin:Meeting', $action, [
			'id' => $foreign->getId(),
		]), BadRequestException::class);
		Assert::same(404, $e?->getHttpCode(), $action);
	}

	$e = Assert::exception(fn() => $scene->tester->run('Admin:Meeting', 'add', [
		'body' => $body->getId(),
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
});

test('documents tab offers PDFs and document action streams PDF', function (): void {
	$scene = MeetingScene::boot(Role::Reader);
	$meetingId = $scene->meeting();

	Assert::contains('Stáhnout PDF', $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'tab' => 'documents',
	]));

	$response = $scene->tester->run('Admin:Meeting', 'document', [
		'id' => $meetingId,
		'type' => 'invitation',
	]);
	Assert::type(Nette\Application\Responses\CallbackResponse::class, $response);
	ob_start();
	$response->send(new Nette\Http\Request(new Nette\Http\UrlScript('http://konsel.test/')), new Nette\Http\Response());
	Assert::same('%PDF-', substr((string) ob_get_clean(), 0, 5));

	$e = Assert::exception(fn() => $scene->tester->run('Admin:Meeting', 'document', [
		'id' => $meetingId,
		'type' => 'nesmysl',
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
});

test('minutes are offered only once the meeting has started; Word version is downloadable', function (): void {
	$scene = MeetingScene::boot();
	$meetingId = $scene->meeting();

	Assert::notContains('Zápis ze zasedání', $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'tab' => 'documents',
	]));
	$e = Assert::exception(fn() => $scene->tester->run('Admin:Meeting', 'document', [
		'id' => $meetingId,
		'type' => 'minutes',
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());

	$scene->meetings->transition($meetingId, App\Model\Meeting\MeetingTransition::Share);
	$scene->meetings->transition($meetingId, App\Model\Meeting\MeetingTransition::Start);
	Assert::contains('Stáhnout pro úpravy ve Wordu', $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'tab' => 'documents',
	]));
	Assert::type(Nette\Application\Responses\CallbackResponse::class, $scene->tester->run('Admin:Meeting', 'document', [
		'id' => $meetingId,
		'type' => 'minutes',
		'format' => 'doc',
	]));
});

test('documents can be archived; approving the minutes archives final versions automatically', function (): void {
	$scene = MeetingScene::boot();
	$meetingId = $scene->meeting();

	PresenterTester::assertRedirect($scene->signal($meetingId, 'archiveDocument', [
		'type' => 'invitation',
	]));
	$html = $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'tab' => 'documents',
	]);
	Assert::contains('Archiv dokumentů', $html);
	Assert::contains('Uložená verze', $html);
	Assert::contains('Nahrát podepsaný originál', $html);

	$archived = $scene->tester->run('Admin:Meeting', 'archived', [
		'id' => $meetingId,
		'documentId' => 1,
	]);
	Assert::type(Nette\Application\Responses\FileResponse::class, $archived);

	foreach (['share', 'start', 'close', 'approve_minutes'] as $transition) {
		PresenterTester::assertRedirect($scene->signal($meetingId, 'transition', [
			'transition' => $transition,
		]));
	}

	$html = $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'tab' => 'documents',
	]);
	Assert::contains('Po ověření zápisu', $html);
	Assert::notContains('Zápis je ověřený, ale v archivu chybí', $html);
	Assert::notContains('Smazat tuto verzi', $html, 'archiv ověřeného zasedání nejde mazat');
});

test('reader sees the archive but cannot archive or delete', function (): void {
	$scene = MeetingScene::boot(Role::Reader);
	$meetingId = $scene->meeting();

	$html = $scene->tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'tab' => 'documents',
	]);
	Assert::contains('Archiv je zatím prázdný.', $html);
	Assert::notContains('Uložit verzi do archivu', $html);
	Assert::notContains('Nahrát podepsaný originál', $html);

	$e = Assert::exception(fn() => $scene->signal($meetingId, 'archiveDocument', [
		'type' => 'invitation',
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});
