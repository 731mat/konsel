<?php

declare(strict_types=1);

use App\Model\Municipality\MunicipalityRepository;
use App\Model\User\Role;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * @return array{PresenterTester, Fixtures, Nette\DI\Container}
 */
function bootTester(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);

	return [new PresenterTester($container), new Fixtures($container), $container];
}

test('admin sees settings form prefilled with municipality data', function (): void {
	[$tester, $fixtures] = bootTester();
	$municipality = $fixtures->municipality();
	$tester->loginAs($fixtures->user($municipality, role: Role::Admin));

	$html = $tester->render('Admin:Settings');

	Assert::contains('Nastavení obce', $html);
	Assert::contains('value="Obec Neplachovice"', $html);
	Assert::contains('Nastavení', $html);
});

test('editor is denied access to settings', function (): void {
	[$tester, $fixtures] = bootTester();
	$tester->loginAs($fixtures->user($fixtures->municipality(), role: Role::Editor));

	$e = Assert::exception(fn() => $tester->run('Admin:Settings'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});

test('anonymous user is redirected to sign in', function (): void {
	[$tester] = bootTester();

	$response = PresenterTester::assertRedirect($tester->run('Admin:Settings'));

	Assert::contains('/prihlaseni', $response->getUrl());
});

test('valid submission saves settings and redirects', function (): void {
	[$tester, $fixtures, $container] = bootTester();
	$municipality = $fixtures->municipality();
	$tester->loginAs($fixtures->user($municipality, role: Role::Admin));

	$response = $tester->submitForm('Admin:Settings', 'settingsForm', [
		'name' => 'Neplachovice',
		'officialName' => 'Obec Neplachovice',
		'ico' => '',
		'population' => '650',
		'street' => 'Neplachovice 1',
		'city' => 'Neplachovice',
		'postalCode' => '74774',
		'email' => '',
		'phone' => '',
		'website' => '',
	]);

	PresenterTester::assertRedirect($response);
	$container->getByType(EntityManagerInterface::class)->clear();
	$profile = $container->getByType(MunicipalityRepository::class)->get($municipality->getId())->getProfile();
	Assert::same('747 74', $profile->postalCode);
	Assert::same(650, $profile->population);
});

test('domain validation error is shown at the form field', function (): void {
	[$tester, $fixtures] = bootTester();
	$tester->loginAs($fixtures->user($fixtures->municipality(), role: Role::Admin));

	$response = $tester->submitForm('Admin:Settings', 'settingsForm', [
		'name' => 'Neplachovice',
		'officialName' => 'Obec Neplachovice',
		'ico' => '12345678',
		'postalCode' => '74774',
	]);

	$html = PresenterTester::renderResponse($response);
	Assert::contains('is-invalid', $html);
	Assert::contains('IČO nemá platný kontrolní součet.', $html);
});

test('admin picks municipality colors; they are applied to the layout and can be reset', function (): void {
	[$tester, $fixtures, $container] = bootTester();
	$municipality = $fixtures->municipality();
	$tester->loginAs($fixtures->user($municipality, role: Role::Admin));

	$form = $tester->render('Admin:Settings', 'appearance');
	Assert::contains('value="#1b5e8c"', $form);
	Assert::contains('Státní modrá a červená', $form);
	Assert::notContains('--bs-primary:', $form, 'výchozí barvy nepotřebují vlastní styl');

	$invalid = $tester->submitForm('Admin:Settings', 'appearanceForm', [
		'primaryColor' => '#f5d76e',
		'accentColor' => '#1b5e8c',
	], action: 'appearance');
	Assert::contains('příliš světlá', PresenterTester::renderResponse($invalid));

	PresenterTester::assertRedirect($tester->submitForm('Admin:Settings', 'appearanceForm', [
		'primaryColor' => '#11457E',
		'accentColor' => '#d7141a',
	], action: 'appearance'));
	$container->getByType(EntityManagerInterface::class)->clear();
	$saved = $container->getByType(MunicipalityRepository::class)->get($municipality->getId())->getBrandColors();
	Assert::same('#11457e', $saved->primary->value);
	Assert::same('#d7141a', $saved->accent->value);

	$page = $tester->render('Admin:Dashboard');
	Assert::contains('<style>:root,[data-bs-theme=light]{--bs-primary:#11457e;', $page);

	PresenterTester::assertRedirect($tester->run('Admin:Settings', 'appearance', [
		'do' => 'resetColors',
	], post: [
		'x' => '1',
	]));
	$container->getByType(EntityManagerInterface::class)->clear();
	Assert::false($container->getByType(MunicipalityRepository::class)->get($municipality->getId())->hasCustomBrandColors());
});
