<?php

declare(strict_types=1);

use App\Model\User\Role;
use App\Model\User\UserFacade;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('admin creates user with temporary password shown once', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($municipality, 'admin@obec.cz', Role::Admin));

	PresenterTester::assertRedirect($tester->submitForm('Admin:User', 'userForm', [
		'name' => 'Nová Úřednice',
		'email' => 'nova@obec.cz',
		'role' => 'editor',
	], action: 'add'));

	$created = array_values(array_filter(
		$container->getByType(UserFacade::class)->findByMunicipality($municipality->getId()),
		static fn($u) => $u->getEmail()->value === 'nova@obec.cz',
	));
	Assert::count(1, $created);
	Assert::true($created[0]->mustChangePassword());
	Assert::same(Role::Editor, $created[0]->getRole());
});

test('user with temporary password is redirected to account page', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$user = $fixtures->user($fixtures->municipality());
	$container->getByType(UserFacade::class)->resetPassword($user->getId());
	$tester = new PresenterTester($container);
	$tester->loginAs($user);

	$response = PresenterTester::assertRedirect($tester->run('Admin:Dashboard'));
	Assert::contains('/admin/account', $response->getUrl());
	Assert::contains('dočasným heslem', $tester->render('Admin:Account'));
});

test('editor cannot manage users', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($fixtures->municipality(), role: Role::Editor));

	$e = Assert::exception(fn() => $tester->run('Admin:User'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});

test('admin cannot open user of another municipality', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($fixtures->municipality(), 'admin@obec.cz', Role::Admin));
	$foreign = $fixtures->user($fixtures->municipality('Cizí'), 'cizi@obec.cz', Role::Admin);

	$e = Assert::exception(fn() => $tester->run('Admin:User', 'edit', [
		'id' => $foreign->getId(),
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
});
