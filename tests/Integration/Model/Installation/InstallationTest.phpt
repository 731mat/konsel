<?php

declare(strict_types=1);

use App\Model\Backup\BackupFacade;
use App\Model\Installation\Command\Install;
use App\Model\Installation\InstallationFacade;
use App\Model\Municipality\Module;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Template\TemplateFacade;
use App\Model\User\Role;
use App\Model\User\UserFacade;
use App\PublicModule\Install\InstallPresenter;
use Nette\Application\Response;
use Nette\Application\Responses\TextResponse;
use Nette\Bridges\ApplicationLatte\Template;
use Nette\Security\User;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

function install(string $email = 'starosta@neplachovice.cz'): Install
{
	return new Install(
		municipalityName: 'Neplachovice',
		officialName: 'Obec Neplachovice',
		ico: '00300535',
		street: 'Hlavní 1',
		city: 'Neplachovice',
		postalCode: '747 74',
		email: 'obec@neplachovice.cz',
		termName: '2026–2030',
		termStartsOn: new DateTimeImmutable('2026-10-20'),
		councilName: 'Zastupitelstvo obce Neplachovice',
		councilSeats: 9,
		boardName: 'Rada obce Neplachovice',
		boardSeats: 5,
		adminName: 'Jana Starostová',
		adminEmail: $email,
		adminPassword: 'dlouhe-heslo-123',
		modules: [Module::ResolutionTasks, Module::AiConnections],
		installTemplates: true,
		dailyBackup: true,
	);
}

test('installation code, environment checks and one-shot install', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$installation = $container->getByType(InstallationFacade::class);

	Assert::true($installation->isRequired());
	$code = $installation->code();
	Assert::match('#^[A-Z2-9]{5}-[A-Z2-9]{5}$#', $code);
	Assert::same($code, $installation->code(), 'kód platí do dokončení');
	Assert::true($installation->verifyCode(strtolower(str_replace('-', ' ', $code))));
	Assert::false($installation->verifyCode('AAAAA-AAAAA'));
	Assert::false($installation->verifyCode(''));
	Assert::true(InstallationFacade::canContinue($installation->checks()));

	$admin = $installation->install(install());
	Assert::false($installation->isRequired());
	Assert::false($installation->verifyCode($code), 'po instalaci kód neplatí');
	Assert::same(Role::Admin, $admin->getRole());
	$municipality = $admin->getMunicipality();
	assert($municipality !== null);
	Assert::same('Neplachovice', $municipality->getName());
	Assert::false($municipality->isModuleEnabled(Module::Plans));
	Assert::true($municipality->isModuleEnabled(Module::AiConnections));

	$people = $container->getByType(PeopleFacade::class);
	$term = $people->findCurrentTerm($municipality->getId());
	assert($term !== null);
	Assert::same('2026–2030', $term->getName());
	Assert::same(['Zastupitelstvo obce Neplachovice', 'Rada obce Neplachovice'], array_map(static fn(App\Model\People\Body $body): string => $body->getName(), $people->findBodies($term->getId())));
	Assert::true(count($container->getByType(TemplateFacade::class)->findResolutionTemplates($municipality->getId())) > 0);
	Assert::true($container->getByType(BackupFacade::class)->getSettings()->isEnabled());

	$container->getByType(User::class)->login('starosta@neplachovice.cz', 'dlouhe-heslo-123');
	Assert::true($container->getByType(User::class)->isLoggedIn());

	Assert::exception(fn() => $installation->install(install('jiny@neplachovice.cz')), InvalidStateException::class);
	Assert::count(1, $container->getByType(UserFacade::class)->findByMunicipality($municipality->getId()));
});

test('term start follows municipal elections every four years', function (): void {
	Assert::same(2026, InstallPresenter::termStartYear(new DateTimeImmutable('2026-11-18')));
	Assert::same(2022, InstallPresenter::termStartYear(new DateTimeImmutable('2026-09-01')));
	Assert::same(2026, InstallPresenter::termStartYear(new DateTimeImmutable('2029-05-01')));
	Assert::same(2030, InstallPresenter::termStartYear(new DateTimeImmutable('2030-10-10')));
});

function html(Response $response): string
{
	Assert::type(TextResponse::class, $response);
	assert($response instanceof TextResponse);
	$source = $response->getSource();

	return $source instanceof Template ? $source->renderToString() : (is_string($source) ? $source : '');
}

test('web wizard walks through all steps and signs the admin in', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$tester = new PresenterTester($container);
	$code = $container->getByType(InstallationFacade::class)->code();

	Assert::contains('/instalace', PresenterTester::assertRedirect($tester->run('Public:Homepage'))->getUrl());
	Assert::contains('/instalace', PresenterTester::assertRedirect($tester->run('Public:Sign', 'in'))->getUrl());
	Assert::contains('/instalace/kod', PresenterTester::assertRedirect($tester->run('Public:Install', 'municipality'))->getUrl(), 'bez kódu jen první krok');
	Assert::contains('Instalační kód', $tester->render('Public:Install', 'code'));
	Assert::contains('Kód nesouhlasí', html($tester->submitForm('Public:Install', 'codeForm', [
		'code' => 'AAAAA-AAAAA',
	], 'code')));
	Assert::contains('/instalace/kontrola', PresenterTester::assertRedirect($tester->submitForm('Public:Install', 'codeForm', [
		'code' => $code,
	], 'code'))->getUrl());
	Assert::contains('Kontrola prostředí', $tester->render('Public:Install', 'check'));
	Assert::contains('/instalace/obec', PresenterTester::assertRedirect($tester->run('Public:Install', 'bodies'))->getUrl(), 'kroky popořadě');

	Assert::contains('data-ares-lookup', $tester->render('Public:Install', 'municipality'));
	PresenterTester::assertRedirect($tester->submitForm('Public:Install', 'municipalityForm', [
		'ico' => '00300535',
		'name' => 'Neplachovice',
		'officialName' => 'Obec Neplachovice',
		'street' => 'Hlavní 1',
		'city' => 'Neplachovice',
		'postalCode' => '747 74',
		'email' => '',
	], 'municipality'));
	Assert::contains('Zastupitelstvo obce Neplachovice', $tester->render('Public:Install', 'bodies'));
	PresenterTester::assertRedirect($tester->submitForm('Public:Install', 'bodiesForm', [
		'termName' => '2026–2030',
		'termStartsOn' => '2026-10-20',
		'councilName' => 'Zastupitelstvo obce Neplachovice',
		'councilSeats' => '7',
		'boardName' => 'Rada obce Neplachovice',
		'boardSeats' => '5',
	], 'bodies'));
	PresenterTester::assertRedirect($tester->submitForm('Public:Install', 'optionsForm', [
		'modules' => ['resolution_tasks', 'plans', 'ai_connections'],
		'installTemplates' => 'on',
	], 'options'));
	$summary = $tester->render('Public:Install', 'admin');
	Assert::contains('Obec Neplachovice, IČO 00300535', $summary);
	Assert::contains('Zastupitelstvo obce Neplachovice (7 členů)', $summary);
	Assert::notContains('Rada obce', $summary, 'rada nezaškrtnutá');

	Assert::contains('Hesla se neshodují', html($tester->submitForm('Public:Install', 'adminForm', [
		'name' => 'Jana Starostová',
		'email' => 'starosta@neplachovice.cz',
		'password' => 'dlouhe-heslo-123',
		'passwordConfirm' => 'jine-heslo-12345',
	], 'admin')));
	$done = PresenterTester::assertRedirect($tester->submitForm('Public:Install', 'adminForm', [
		'name' => 'Jana Starostová',
		'email' => 'starosta@neplachovice.cz',
		'password' => 'dlouhe-heslo-123',
		'passwordConfirm' => 'dlouhe-heslo-123',
	], 'admin'));
	Assert::contains('/admin', $done->getUrl());
	Assert::true($container->getByType(User::class)->isLoggedIn());
	Assert::false($container->getByType(InstallationFacade::class)->isRequired());
	Assert::false($container->getByType(BackupFacade::class)->getSettings()->isEnabled(), 'zálohy nezaškrtnuté');
	Assert::contains('/prihlaseni', PresenterTester::assertRedirect($tester->run('Public:Install', 'code'))->getUrl(), 'po instalaci průvodce zmizí');
});
