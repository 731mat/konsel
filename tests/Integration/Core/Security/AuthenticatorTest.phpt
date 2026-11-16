<?php

declare(strict_types=1);

use App\Core\Security\Authenticator;
use App\Model\Shared\TransactionManager;
use App\Model\User\LoginThrottle;
use App\Model\User\UserRepository;
use Nette\Security\AuthenticationException;
use Nette\Security\SimpleIdentity;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array{Authenticator, Fixtures, Nette\DI\Container}
 */
function bootAuthenticator(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);

	return [$container->getByType(Authenticator::class), new Fixtures($container), $container];
}

test('valid credentials return identity with role and municipality', function (): void {
	[$authenticator, $fixtures] = bootAuthenticator();
	$municipality = $fixtures->municipality();
	$user = $fixtures->user($municipality);

	$identity = $authenticator->authenticate(' JANA@neplachovice.cz ', Fixtures::Password);

	Assert::same($user->getId(), $identity->getId());
	Assert::same(['editor'], $identity->getRoles());
	Assert::same($municipality->getId(), $identity->getData()['municipalityId']);
	Assert::same('2026-11-16 10:00', $user->getLastLoginAt()?->format('Y-m-d H:i'));
});

test('wrong password and unknown email give the same message', function (): void {
	[$authenticator, $fixtures] = bootAuthenticator();
	$fixtures->user($fixtures->municipality());

	Assert::exception(fn() => $authenticator->authenticate('jana@neplachovice.cz', 'spatne'), AuthenticationException::class, 'Nesprávný e-mail nebo heslo.');
	Assert::exception(fn() => $authenticator->authenticate('nikdo@neplachovice.cz', 'spatne'), AuthenticationException::class, 'Nesprávný e-mail nebo heslo.');
});

test('deactivated user cannot log in and loses session identity', function (): void {
	[$authenticator, $fixtures, $container] = bootAuthenticator();
	$user = $fixtures->user($fixtures->municipality());
	$container->getByType(TransactionManager::class)->run(fn() => $user->deactivate());

	Assert::exception(fn() => $authenticator->authenticate('jana@neplachovice.cz', Fixtures::Password), AuthenticationException::class, '%a%deaktivovaný%a%');
	Assert::null($authenticator->wakeupIdentity(new SimpleIdentity($user->getId())));
});

test('identity is refreshed from database on wakeup', function (): void {
	[$authenticator, $fixtures, $container] = bootAuthenticator();
	$user = $fixtures->user($fixtures->municipality());
	$container->getByType(TransactionManager::class)->run(fn() => $user->changeRole(App\Model\User\Role::Admin));

	$identity = $authenticator->wakeupIdentity($authenticator->sleepIdentity(new SimpleIdentity($user->getId(), ['editor'])));

	Assert::same(['admin'], $identity?->getRoles());
});

test('login is blocked after too many failures, even with correct password', function (): void {
	[$authenticator, $fixtures] = bootAuthenticator();
	$fixtures->user($fixtures->municipality());

	for ($i = 0; $i < LoginThrottle::MaxFailuresPerEmail; $i++) {
		Assert::exception(fn() => $authenticator->authenticate('jana@neplachovice.cz', 'spatne'), AuthenticationException::class);
	}

	Assert::exception(
		fn() => $authenticator->authenticate('jana@neplachovice.cz', Fixtures::Password),
		AuthenticationException::class,
		'Příliš mnoho neúspěšných pokusů%A%',
	);
});

test('successful login resets the failure counter', function (): void {
	[$authenticator, $fixtures, $container] = bootAuthenticator();
	$fixtures->user($fixtures->municipality());

	for ($i = 0; $i < LoginThrottle::MaxFailuresPerEmail - 1; $i++) {
		Assert::exception(fn() => $authenticator->authenticate('jana@neplachovice.cz', 'spatne'), AuthenticationException::class);
	}

	/** @var Symfony\Component\Clock\MockClock $clock */
	$clock = $container->getByType(Psr\Clock\ClockInterface::class);
	$clock->sleep(1);
	$authenticator->authenticate('jana@neplachovice.cz', Fixtures::Password);
	$clock->sleep(1);
	Assert::exception(fn() => $authenticator->authenticate('jana@neplachovice.cz', 'spatne'), AuthenticationException::class, 'Nesprávný e-mail nebo heslo.');
	Assert::noError(fn() => $authenticator->authenticate('jana@neplachovice.cz', Fixtures::Password));
	Assert::true($container->getByType(UserRepository::class)->get(1)->isActive());
});
