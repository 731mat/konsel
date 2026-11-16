<?php

declare(strict_types=1);

use App\Model\Access\AccessFacade;
use App\Model\Access\MeetingAccessToken;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\AccessDeniedException;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array{AccessFacade, MeetingScenario, Nette\DI\Container}
 */
function bootAccess(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	foreach (['Dvořák', 'Svobodová'] as $lastName) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson('Člen', $lastName, email: strtolower($lastName) . '@obec.cz'));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
	}

	return [$container->getByType(AccessFacade::class), $scenario, $container];
}

test('links are issued to all members, only hash is stored and token authenticates', function (): void {
	[$facade, $scenario, $container] = bootAccess();

	$issued = $facade->issueForMembers($scenario->meeting->getId());
	Assert::count(2, $issued);
	Assert::match('~^[A-Za-z0-9_-]{43}$~', $issued[0]->token);

	$hashes = $container->getByType(Connection::class)->fetchFirstColumn('SELECT token_hash FROM meeting_access_token');
	Assert::notContains($issued[0]->token, $hashes);
	Assert::contains(MeetingAccessToken::hash($issued[0]->token), $hashes);

	$token = $facade->authenticate($issued[0]->token);
	Assert::same('Člen Dvořák', $token->getPerson()->getFullName());
	Assert::same(1, $token->getUseCount());
	Assert::same('2027-02-07', $token->getExpiresAt()->format('Y-m-d'), 'zasedání 9. 12. + 60 dní');
});

test('reissue revokes previous link; revoked, expired and forged links are refused', function (): void {
	[$facade, $scenario, $container] = bootAccess();
	[$first] = $facade->issueForMembers($scenario->meeting->getId());
	$personId = $first->accessToken->getPerson()->getId();

	$second = $facade->issueFor($scenario->meeting->getId(), $personId);
	Assert::exception(fn() => $facade->authenticate($first->token), AccessDeniedException::class);
	Assert::noError(fn() => $facade->authenticate($second->token));

	$facade->revoke($second->accessToken->getId());
	Assert::exception(fn() => $facade->authenticate($second->token), AccessDeniedException::class);
	Assert::exception(fn() => $facade->getValid($second->accessToken->getId()), AccessDeniedException::class);
	Assert::exception(fn() => $facade->authenticate(str_repeat('a', 43)), AccessDeniedException::class);
	Assert::exception(fn() => $facade->authenticate('../../etc'), AccessDeniedException::class);

	$third = $facade->issueFor($scenario->meeting->getId(), $personId);
	$clock = $container->getByType(ClockInterface::class);
	assert($clock instanceof MockClock);
	$clock->modify('2027-02-08 00:00');
	Assert::exception(fn() => $facade->authenticate($third->token), AccessDeniedException::class, '%A%platnost skončila%A%');
});
