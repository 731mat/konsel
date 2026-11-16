<?php

declare(strict_types=1);

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\Email;
use App\Model\User\Role;
use App\Model\User\User;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

$municipality = new Municipality('Neplachovice', 'neplachovice');
$email = new Email('jana@neplachovice.cz');

test('municipality user is active by default', function () use ($municipality, $email): void {
	$user = new User($municipality, $email, 'Jana Nováková', Role::Editor, 'hash');

	Assert::true($user->isActive());
	Assert::false($user->mustChangePassword());
	Assert::same($municipality, $user->getMunicipality());
});

test('municipality roles require municipality', function () use ($email): void {
	foreach ([Role::Admin, Role::Editor, Role::Reader] as $role) {
		Assert::exception(fn() => new User(null, $email, 'Jana', $role, 'hash'), ValidationException::class);
	}
});

test('superadmin must not belong to municipality', function () use ($municipality, $email): void {
	Assert::exception(fn() => new User($municipality, $email, 'Admin', Role::SuperAdmin, 'hash'), ValidationException::class);
	Assert::noError(fn() => new User(null, $email, 'Admin', Role::SuperAdmin, 'hash'));
});

test('role change keeps municipality invariant', function () use ($municipality, $email): void {
	$user = new User($municipality, $email, 'Jana', Role::Editor, 'hash');
	$user->changeRole(Role::Admin);

	Assert::same(Role::Admin, $user->getRole());
	Assert::exception(fn() => $user->changeRole(Role::SuperAdmin), ValidationException::class);
});

test('password change can force change on next login', function () use ($municipality, $email): void {
	$user = new User($municipality, $email, 'Jana', Role::Editor, 'old');
	$user->changePassword('new', mustChangePassword: true);

	Assert::same('new', $user->getPasswordHash());
	Assert::true($user->mustChangePassword());
});
