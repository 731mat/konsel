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
	Assert::same('<p>Proč se zvyšuje položka na odpady?</p><p>Děkuji.</p>', $threads[0]->comment->getBody()->html, 'prostý text (bez JavaScriptu) se převede na odstavce');
	Assert::true($threads[0]->comment->isFromMember());
	Assert::same('Petr Novotný', $threads[0]->comment->getAuthorName());
	Assert::same(['<p>Kvůli novému ceníku svozové firmy.</p>'], array_map(static fn($c) => $c->getBody()->html, $threads[0]->replies));
	Assert::same([
		$itemId => 3,
	], $facade->countByMeeting($scenario->meeting));

	$reply = $threads[0]->replies[0];
	$e = Assert::exception(fn() => $facade->addByUser($itemId, $clerk->getId(), 'Odpověď na odpověď', $reply->getId()), ValidationException::class);
	Assert::same('parentId', $e?->field);
	Assert::exception(fn() => $facade->addByUser($itemId, $clerk->getId(), '   '), ValidationException::class, 'Napište text komentáře.');
	Assert::exception(fn() => $facade->addByUser($itemId, $clerk->getId(), '<p></p><p><br></p>'), ValidationException::class, 'Napište text komentáře.'); // prázdný odstavec z editoru
});

test('formatted comment keeps allowed tags and loses anything dangerous', function (): void {
	[$facade, $scenario, $fixtures] = bootComments();
	$clerk = $fixtures->user($scenario->municipality);

	$comment = $facade->addByUser(
		$scenario->item->getId(),
		$clerk->getId(),
		'<p onclick="steal()">Viz <strong>rozpočet</strong> a <a href="javascript:alert(1)">tohle</a>'
		. '<script>alert(1)</script></p><ul><li><p>položka</p></li></ul><p><a href="https://obec.cz/rozpocet">web</a></p><img src=x onerror=alert(1)>',
	);

	$html = $comment->getBody()->html;
	Assert::contains('<strong>rozpočet</strong>', $html);
	Assert::contains('<ul><li><p>položka</p></li></ul>', $html);
	Assert::contains('<a href="https://obec.cz/rozpocet" rel="noopener noreferrer nofollow" target="_blank">web</a>', $html);
	foreach (['onclick', 'javascript:', '<script', 'alert', '<img', 'onerror'] as $forbidden) {
		Assert::notContains($forbidden, $html);
	}

	Assert::exception(
		fn() => $facade->addByUser($scenario->item->getId(), $clerk->getId(), '<p>' . str_repeat('a', 5001) . '</p>'),
		ValidationException::class,
		'Komentář může mít nejvýše 5000 znaků.',
	);
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
	Assert::true($threads[0]->comment->getBody()->isEmpty());
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

test('comment carries files; file-only comment is allowed; deleting comment removes files', function (): void {
	[$facade, $scenario, $fixtures, $container] = bootComments();
	$user = $fixtures->user($scenario->municipality);
	$file = static function (string $name, string $content): App\Model\Storage\UploadedFile {
		$stream = fopen('php://memory', 'w+b');
		assert($stream !== false);
		fwrite($stream, $content);
		rewind($stream);

		return new App\Model\Storage\UploadedFile($stream, $name);
	};
	$storage = $container->getByType(App\Model\Storage\FileStorage::class);

	$comment = $facade->addByUser($scenario->item->getId(), $user->getId(), '', null, [$file('foto.jpg', "\xFF\xD8\xFFjpeg"), $file('rozpocet.xlsx', 'PK')]);
	Assert::same('', $comment->getBody()->html);
	Assert::same(['foto.jpg', 'rozpocet.xlsx'], array_map(static fn($a) => $a->getTitle(), $comment->getAttachments()));
	Assert::same($scenario->meeting, $comment->getAttachments()[0]->getMeeting());
	Assert::same([], $container->getByType(App\Model\Attachment\AttachmentFacade::class)->findByAgendaItem($scenario->item->getId()), 'soubory komentáře nejsou mezi přílohami bodu');

	Assert::exception(fn() => $facade->addByUser($scenario->item->getId(), $user->getId(), 'Script', null, [$file('x.exe', 'MZ')]), ValidationException::class, '%A%.exe není povolený%A%');

	$stored = $comment->getAttachments()[0]->getFile();
	Assert::true($storage->exists($stored));
	$facade->deleteByUser($comment->getId(), $user->getId());
	Assert::false($storage->exists($stored));
});
