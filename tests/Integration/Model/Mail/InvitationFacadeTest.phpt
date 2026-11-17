<?php

declare(strict_types=1);

use App\Model\Access\AccessFacade;
use App\Model\Mail\InvitationFacade;
use App\Model\Mail\MailStatus;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Municipality\Command\UpdateMunicipalitySettings;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\AccessDeniedException;
use App\Model\Shared\Exception\InvalidStateException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\FakeMailer;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * Zastupitelstvo se 3 členy: dva s e-mailem, jeden bez.
 *
 * @return array{InvitationFacade, MeetingScenario, FakeMailer, Nette\DI\Container}
 */
function bootInvitations(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$container->getByType(MunicipalityFacade::class)->updateSettings($scenario->municipality->getId(), new UpdateMunicipalitySettings(
		name: 'Neplachovice',
		officialName: 'Obec Neplachovice',
		email: 'obec@neplachovice.cz',
	));
	$people = $container->getByType(PeopleFacade::class);
	foreach ([['Jan', 'Dvořák', 'dvorak@obec.cz'], ['Marie', 'Svobodová', 'svobodova@obec.cz'], ['Petr', 'Bezmailu', null]] as [$first, $last, $email]) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson($first, $last, email: $email));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
	}

	$mailer = $container->getByType(App\Model\Mail\Mailer::class);
	assert($mailer instanceof FakeMailer);

	return [$container->getByType(InvitationFacade::class), $scenario, $mailer, $container];
}

$portalUrl = static fn(string $token): string => "https://konsel.test/odkaz/{$token}";

test('invitations go only to a shared meeting', function () use ($portalUrl): void {
	[$facade, $scenario, $mailer] = bootInvitations();

	Assert::exception(fn() => $facade->send($scenario->meeting->getId(), $portalUrl), InvalidStateException::class, '%a%sdílejte%a%');
	Assert::same([], $mailer->sent);
});

test('members with e-mail get personal link, program and PDF; others are reported with their link', function () use ($portalUrl): void {
	[$facade, $scenario, $mailer, $container] = bootInvitations();
	$container->getByType(MeetingFacade::class)->transition($scenario->meeting->getId(), MeetingTransition::Share);

	$result = $facade->send($scenario->meeting->getId(), $portalUrl);

	Assert::count(2, $result->sent);
	Assert::same(['Petr Bezmailu'], array_map(static fn($t) => $t->accessToken->getPerson()->getFullName(), $result->withoutEmail));
	Assert::count(2, $mailer->sent);

	$message = $mailer->sent[0];
	Assert::same('dvorak@obec.cz', $message->to->value);
	Assert::same('Pozvánka: 1. zasedání – Zastupitelstvo obce Neplachovice, 9. 12. 2026', $message->subject);
	Assert::same('Obec Neplachovice', $message->fromName);
	Assert::same('obec@neplachovice.cz', $message->replyTo?->value);
	Assert::contains('https://konsel.test/odkaz/' . $result->sent[0]->token, $message->html);
	Assert::contains('středa 9. prosince 2026 v 18:00', $message->html);
	Assert::contains('Obecní dům', $message->html);
	Assert::contains('background:#1b5e8c;', $message->html, 'tlačítko v barvě obce (neescapovaná platná barva)');
	Assert::contains('<li style="margin-bottom:4px;">Rozpočet na rok 2027</li>', $message->html);
	Assert::count(1, $message->attachments);
	Assert::match('pozvanka-%a%.pdf', $message->attachments[0]->fileName);
	Assert::true(str_starts_with($message->attachments[0]->content, '%PDF'));

	$token = $container->getByType(AccessFacade::class)->authenticate($result->sent[0]->token);
	Assert::same('Jan Dvořák', $token->getPerson()->getFullName(), 'odkaz z e-mailu funguje');

	$log = $facade->findLog($scenario->meeting->getId());
	Assert::count(2, $log);
	Assert::same([MailStatus::Sent, MailStatus::Sent], array_map(static fn($l) => $l->getStatus(), $log));
});

test('failed delivery is logged with the error; resending to one member replaces only his link', function () use ($portalUrl): void {
	[$facade, $scenario, $mailer, $container] = bootInvitations();
	$container->getByType(MeetingFacade::class)->transition($scenario->meeting->getId(), MeetingTransition::Share);
	$mailer->failFor = ['svobodova@obec.cz'];

	$result = $facade->send($scenario->meeting->getId(), $portalUrl);
	Assert::count(1, $result->sent);
	Assert::count(1, $result->failed);
	Assert::count(2, $result->notDelivered());
	$failed = array_values(array_filter($facade->findLog($scenario->meeting->getId()), static fn($l) => $l->getStatus() === MailStatus::Failed));
	Assert::same('svobodova@obec.cz', $failed[0]->getRecipient());
	Assert::contains('rejected', (string) $failed[0]->getError());

	$mailer->failFor = [];
	$oldToken = $result->failed[0]->token;
	$again = $facade->send($scenario->meeting->getId(), $portalUrl, $result->failed[0]->accessToken->getPerson()->getId());
	Assert::count(1, $again->sent);
	$last = end($mailer->sent);
	Assert::same('svobodova@obec.cz', $last === false ? null : $last->to->value);

	$access = $container->getByType(AccessFacade::class);
	Assert::exception(fn() => $access->authenticate($oldToken), AccessDeniedException::class);
	Assert::noError(fn() => $access->authenticate($again->sent[0]->token));
	Assert::noError(fn() => $access->authenticate($result->sent[0]->token)); // odkazy ostatních zůstávají
});
