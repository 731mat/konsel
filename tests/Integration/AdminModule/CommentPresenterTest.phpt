<?php

declare(strict_types=1);

use App\Model\Comment\CommentFacade;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('reader can join the discussion, delete own comment and reply', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$scenario = MeetingScenario::create($container);
	$reader = $fixtures->user($scenario->municipality, 'ctenar@obec.cz', Role::Reader);
	$tester = new PresenterTester($container);
	$tester->loginAs($reader);
	$facade = $container->getByType(CommentFacade::class);
	$params = [
		'id' => $scenario->item->getId(),
	];

	PresenterTester::assertRedirect($tester->submitForm('Admin:Agenda', 'commentForm', [
		'parentId' => '',
		'body' => 'Mám dotaz k rozpočtu.',
	], action: 'detail', params: $params));
	$comment = $facade->findThreads($scenario->item->getId())[0]->comment;

	PresenterTester::assertRedirect($tester->submitForm('Admin:Agenda', 'commentForm', [
		'parentId' => (string) $comment->getId(),
		'body' => 'Doplním: jde o položku 2212.',
	], action: 'detail', params: $params));

	$html = $tester->render('Admin:Agenda', 'detail', $params);
	Assert::contains('Mám dotaz k rozpočtu.', $html);
	Assert::contains('comment-replies', $html);
	Assert::contains('>JN</span>', $html, 'iniciály Jana Nováková');

	PresenterTester::assertRedirect($tester->run('Admin:Agenda', 'detail', $params + [
		'do' => 'deleteComment',
		'commentId' => $comment->getId(),
	], post: [
		'x' => '1',
	]));
	Assert::contains('Komentář byl smazán.', $tester->render('Admin:Agenda', 'detail', $params));
});

test('formatted comment from the editor is rendered as safe HTML', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$scenario = MeetingScenario::create($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($scenario->municipality, 'editor@obec.cz', Role::Editor));
	$params = [
		'id' => $scenario->item->getId(),
	];

	PresenterTester::assertRedirect($tester->submitForm('Admin:Agenda', 'commentForm', [
		'parentId' => '',
		'body' => '<p>Prosím <strong>doplnit</strong>:</p><ol><li><p>smlouvu</p></li></ol><p><a href="javascript:alert(1)" onmouseover="x()">klik</a><script>alert(1)</script></p>',
	], action: 'detail', params: $params));

	$html = $tester->render('Admin:Agenda', 'detail', $params);
	Assert::contains('<p>Prosím <strong>doplnit</strong>:</p><ol><li><p>smlouvu</p></li></ol>', $html);
	Assert::notContains('javascript:alert', $html);
	Assert::notContains('<script>alert(1)', $html);
	Assert::notContains('onmouseover', $html);
	Assert::contains('data-rich-editor', $html, 'pole komentáře má editor');
});
