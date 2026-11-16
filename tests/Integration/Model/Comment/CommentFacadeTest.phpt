<?php

declare(strict_types=1);

use App\Model\Comment\CommentFacade;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\People\Command\SavePerson;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\AccessDeniedException;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array{CommentFacade, MeetingScenario, Fixtures, Nette\DI\Container}
 */
function bootComments(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$scenario = MeetingScenario::create($container);

	return [$container->getByType(CommentFacade::class), $scenario, $fixtures, $container];
}

test('users and members discuss in one-level threads', function (): void {
	[$facade, $scenario, $fixtures, $container] = bootComments();
	$clerk = $fixtures->user($scenario->municipality);
	$member = $container->getByType(PeopleFacade::class)->createPerson($scenario->municipality->getId(), new SavePerson('Petr', 'Novotný'));
	$itemId = $scenario->item->getId();

	$question = $facade->addByPerson($itemId, $member->getId(), "Proč se zvyšuje položka na odpady?\r\n\r\nDěkuji.");
	$facade->addByUser($itemId, $clerk->getId(), 'Kvůli novému ceníku svozové firmy.', $question->getId());
	$facade->addByUser($itemId, $clerk->getId(), 'Doplnila jsem přílohu.');

	$threads = $facade->findThreads($itemId);
	Assert::count(2, $threads);
	Assert::same("Proč se zvyšuje položka na odpady?\n\nDěkuji.", $threads[0]->comment->getBody());
	Assert::true($threads[0]->comment->isFromMember());
	Assert::same('Petr Novotný', $threads[0]->comment->getAuthorName());
	Assert::same(['Kvůli novému ceníku svozové firmy.'], array_map(static fn($c) => $c->getBody(), $threads[0]->replies));
	Assert::same([
		$itemId => 3,
	], $facade->countByMeeting($scenario->meeting));

	$reply = $threads[0]->replies[0];
	$e = Assert::exception(fn() => $facade->addByUser($itemId, $clerk->getId(), 'Odpověď na odpověď', $reply->getId()), ValidationException::class);
	Assert::same('parentId', $e?->field);
	Assert::exception(fn() => $facade->addByUser($itemId, $clerk->getId(), '   '), ValidationException::class, 'Napište text komentáře.');
});

test('only author or moderator can delete; deleted comment with replies stays as placeholder', function (): void {
	[$facade, $scenario, $fixtures] = bootComments();
	$author = $fixtures->user($scenario->municipality, 'autor@obec.cz');
	$other = $fixtures->user($scenario->municipality, 'jiny@obec.cz');
	$admin = $fixtures->user($scenario->municipality, 'spravce@obec.cz', Role::Admin);
	$itemId = $scenario->item->getId();
	$comment = $facade->addByUser($itemId, $author->getId(), 'Původní');
	$facade->addByUser($itemId, $other->getId(), 'Reakce', $comment->getId());
	$lonely = $facade->addByUser($itemId, $other->getId(), 'Samotný');

	Assert::exception(fn() => $facade->deleteByUser($comment->getId(), $other->getId()), AccessDeniedException::class);
	$facade->deleteByUser($comment->getId(), $author->getId());
	$facade->deleteByUser($lonely->getId(), $admin->getId(), moderator: true);

	$threads = $facade->findThreads($itemId);
	Assert::count(1, $threads, 'smazaný komentář bez odpovědí zmizí');
	Assert::true($threads[0]->comment->isDeleted());
	Assert::same('', $threads[0]->comment->getBody());
	Assert::count(1, $threads[0]->replies);
});

test('discussion is closed after minutes are approved', function (): void {
	[$facade, $scenario, $fixtures, $container] = bootComments();
	$user = $fixtures->user($scenario->municipality);
	$meetings = $container->getByType(MeetingFacade::class);
	foreach ([MeetingTransition::Share, MeetingTransition::Start, MeetingTransition::Close, MeetingTransition::ApproveMinutes] as $transition) {
		$meetings->transition($scenario->meeting->getId(), $transition);
	}

	Assert::exception(fn() => $facade->addByUser($scenario->item->getId(), $user->getId(), 'Pozdě'), InvalidStateException::class);
});
