<?php

declare(strict_types=1);

use App\Model\Access\AccessFacade;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Storage\UploadedFile;
use Nette\Application\BadRequestException;
use Nette\Application\Responses\FileResponse;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

function memoryFile(string $name): UploadedFile
{
	$stream = fopen('php://memory', 'w+b');
	assert($stream !== false);
	fwrite($stream, '%PDF-1.4');
	rewind($stream);

	return new UploadedFile($stream, $name);
}

test('member enters by link, reads program and attachments and comments', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$member = $people->createPerson($scenario->municipality->getId(), new SavePerson('Petr', 'Novotný'));
	$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($member->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
	$container->getByType(AgendaFacade::class)->updateItem($scenario->item->getId(), new SaveAgendaItem('Rozpočet na rok 2027', 'Vyrovnaný rozpočet.', 'Zastupitelstvo schvaluje rozpočet.'));
	$attachments = $container->getByType(AttachmentFacade::class);
	[$public] = $attachments->upload($scenario->item->getId(), [memoryFile('rozpočet.pdf')], null);
	[$internal] = $attachments->upload($scenario->item->getId(), [memoryFile('interní.pdf')], null, publicForMembers: false);
	[$issued] = $container->getByType(AccessFacade::class)->issueForMembers($scenario->meeting->getId());
	$tester = new PresenterTester($container);

	$redirect = PresenterTester::assertRedirect($tester->run('Portal:Entry', 'default', [
		'token' => $issued->token,
	]));
	Assert::contains('/portal', $redirect->getUrl());
	Assert::notContains($issued->token, $redirect->getUrl());

	$html = $tester->render('Portal:Meeting');
	Assert::contains('Dobrý den, Petr Novotný', $html);
	Assert::contains('Rozpočet na rok 2027', $html);

	$itemHtml = $tester->render('Portal:Meeting', 'item', [
		'id' => $scenario->item->getId(),
	]);
	Assert::contains('Zastupitelstvo schvaluje rozpočet.', $itemHtml);
	Assert::contains('rozpočet.pdf', $itemHtml);
	Assert::notContains('interní.pdf', $itemHtml);

	Assert::type(FileResponse::class, $tester->run('Portal:Meeting', 'attachment', [
		'id' => $public->getId(),
	]));
	$e = Assert::exception(fn() => $tester->run('Portal:Meeting', 'attachment', [
		'id' => $internal->getId(),
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());

	PresenterTester::assertRedirect($tester->submitForm('Portal:Meeting', 'commentForm', [
		'parentId' => '',
		'body' => 'Mám dotaz k položce na odpady.',
	], action: 'item', params: [
		'id' => $scenario->item->getId(),
	]));
	$thread = $container->getByType(CommentFacade::class)->findThreads($scenario->item->getId())[0];
	Assert::true($thread->comment->isFromMember());
	Assert::same('Petr Novotný', $thread->comment->getAuthorName());
});

test('invalid link shows explanation, revoked link logs out', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$member = $people->createPerson($scenario->municipality->getId(), new SavePerson('Petr', 'Novotný'));
	$access = $container->getByType(AccessFacade::class);
	$issued = $access->issueFor($scenario->meeting->getId(), $member->getId());
	$tester = new PresenterTester($container);

	Assert::contains('Odkaz je neplatný nebo vypršel', $tester->render('Portal:Entry', 'default', [
		'token' => str_repeat('x', 43),
	]));

	$tester->run('Portal:Entry', 'default', [
		'token' => $issued->token,
	]);
	$access->revoke($issued->accessToken->getId());
	$redirect = PresenterTester::assertRedirect($tester->run('Portal:Meeting'));
	Assert::contains('invalid', $redirect->getUrl());
});

test('admin issues links and sees them once', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$member = $people->createPerson($scenario->municipality->getId(), new SavePerson('Petr', 'Novotný', email: 'petr@obec.cz'));
	$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($member->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
	$tester = new PresenterTester($container);
	$tester->loginAs((new Tests\Support\Fixtures($container))->user($scenario->municipality));

	$html = PresenterTester::renderResponse($tester->run('Admin:Meeting', 'links', [
		'id' => $scenario->meeting->getId(),
		'do' => 'issueLinks',
	], post: [
		'x' => '1',
	]));
	Assert::match('~http://konsel\.test/odkaz/[A-Za-z0-9_-]{43}~', $html);
	Assert::contains('mailto:petr@obec.cz', $html);

	Assert::notMatch('~/odkaz/[A-Za-z0-9_-]{43}~', $tester->render('Admin:Meeting', 'links', [
		'id' => $scenario->meeting->getId(),
	]));
});
