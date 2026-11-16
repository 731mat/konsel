<?php

declare(strict_types=1);

use App\Core\Security\Authenticator;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\Command\UpdateUser;
use App\Model\User\Role;
use App\Model\User\UserFacade;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array{UserFacade, Fixtures, Nette\DI\Container}
 */
function bootUsers(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);

	return [$container->getByType(UserFacade::class), new Fixtures($container), $container];
}

test('last active admin cannot be demoted or deactivated', function (): void {
	[$facade, $fixtures] = bootUsers();
	$municipality = $fixtures->municipality();
	$admin = $fixtures->user($municipality, 'admin@obec.cz', Role::Admin);
	$editor = $fixtures->user($municipality, 'editor@obec.cz', Role::Editor);

	Assert::exception(fn() => $facade->deactivate($admin->getId(), $editor->getId()), InvalidStateException::class, '%A%alespoň jednoho aktivního správce%A%');
	Assert::exception(fn() => $facade->update($admin->getId(), new UpdateUser('admin@obec.cz', 'A', Role::Editor), $editor->getId()), InvalidStateException::class);

	$facade->update($editor->getId(), new UpdateUser('editor@obec.cz', 'E', Role::Admin), $admin->getId());
	Assert::noError(fn() => $facade->deactivate($admin->getId(), $editor->getId()));
});

test('user cannot change own role or deactivate self', function (): void {
	[$facade, $fixtures] = bootUsers();
	$municipality = $fixtures->municipality();
	$admin = $fixtures->user($municipality, 'admin@obec.cz', Role::Admin);
	$fixtures->user($municipality, 'admin2@obec.cz', Role::Admin);

	Assert::exception(fn() => $facade->deactivate($admin->getId(), $admin->getId()), InvalidStateException::class);
	Assert::exception(fn() => $facade->update($admin->getId(), new UpdateUser('admin@obec.cz', 'A', Role::Reader), $admin->getId()), InvalidStateException::class);
});

test('password reset forces change and old password stops working', function (): void {
	[$facade, $fixtures, $container] = bootUsers();
	$user = $fixtures->user($fixtures->municipality());
	$authenticator = $container->getByType(Authenticator::class);

	$temporary = $facade->resetPassword($user->getId());

	Assert::true($user->mustChangePassword());
	Assert::exception(fn() => $authenticator->authenticate('jana@neplachovice.cz', Fixtures::Password), Nette\Security\AuthenticationException::class);
	Assert::true($authenticator->authenticate('jana@neplachovice.cz', $temporary)->getData()['mustChangePassword']);
});

test('own password change validates current password and clears forced change', function (): void {
	[$facade, $fixtures] = bootUsers();
	$user = $fixtures->user($fixtures->municipality());
	$facade->resetPassword($user->getId());

	$e = Assert::exception(fn() => $facade->changeOwnPassword($user->getId(), 'spatne', 'nove-heslo-123'), ValidationException::class);
	Assert::same('currentPassword', $e?->field);
	Assert::exception(fn() => $facade->changeOwnPassword($user->getId(), 'x', 'kratke'), ValidationException::class, 'Heslo musí mít alespoň 10 znaků.');
});
