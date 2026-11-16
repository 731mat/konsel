<?php

declare(strict_types=1);

use App\Application\User\Command\CreateUser;
use App\Application\User\UserFacade;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('password is hashed, never stored in plain text', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$user = (new Fixtures($container))->user((new Fixtures($container))->municipality());

	Assert::notSame(Fixtures::Password, $user->getPasswordHash());
	Assert::true(password_verify(Fixtures::Password, $user->getPasswordHash()));
});

test('duplicate email is rejected case-insensitively', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$fixtures->user($municipality, 'jana@neplachovice.cz');

	Assert::exception(fn() => $fixtures->user($municipality, 'JANA@neplachovice.cz'), ValidationException::class, '%a%už existuje.');
});

test('short password is rejected', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);

	Assert::exception(
		fn() => $container->getByType(UserFacade::class)->create(new CreateUser('a@b.cz', 'A', Role::SuperAdmin, 'kratke')),
		ValidationException::class,
		'Heslo musí mít alespoň 10 znaků.',
	);
});
