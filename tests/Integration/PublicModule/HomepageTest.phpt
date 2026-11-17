<?php

declare(strict_types=1);

use App\Model\Municipality\Command\CreateMunicipality;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\Project\Command\SaveProject;
use App\Model\Project\ProjectFacade;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('crossroad shows citizens the public tasks of the only municipality and members the sign-in form', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$container->getByType(MunicipalityFacade::class)->changeBrandColors($municipality->getId(), '#1e6b45', '#d9a441');
	$projects = $container->getByType(ProjectFacade::class);
	$public = $projects->create($municipality->getId(), new SaveProject('Nová mateřská škola'), 'Úřad');
	$projects->setPublic($public->getId(), true);
	$projects->create($municipality->getId(), new SaveProject('Interní záměr'), 'Úřad');
	$tester = new PresenterTester($container);

	$html = $tester->render('Public:Homepage');

	Assert::contains('Zasedání zastupitelstva bez papírování', $html);
	Assert::contains('Jsem občan', $html);
	Assert::contains('Jsem zastupitel', $html);
	Assert::contains('Nová mateřská škola', $html);
	Assert::notContains('Interní záměr', $html);
	Assert::contains('/obec/neplachovice/podnety', $html);
	Assert::contains('name="password"', $html, 'přihlášení přímo na rozcestníku');
	Assert::contains('odkaz z pozvánky', $html);
	Assert::contains('--bs-primary:#1e6b45', $html, 'jediná obec – její barvy');
});

test('with more municipalities the citizen picks one', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$fixtures->municipality();
	$container->getByType(MunicipalityFacade::class)->create(new CreateMunicipality('Hlavnice'));
	$tester = new PresenterTester($container);

	$html = $tester->render('Public:Homepage');

	Assert::contains('Vyberte svou obec', $html);
	Assert::contains('/obec/neplachovice/podnety', $html);
	Assert::contains('/obec/hlavnice/podnety', $html);
	Assert::notContains('--bs-primary:', $html);
});

test('member signs in from the crossroad', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$fixtures->user($fixtures->municipality(), 'zastupitel@obec.cz');
	$tester = new PresenterTester($container);

	$wrong = $tester->submitForm('Public:Homepage', 'signInForm', [
		'email' => 'zastupitel@obec.cz',
		'password' => 'spatne-heslo',
	]);
	Assert::contains('Jsem zastupitel', PresenterTester::renderResponse($wrong), 'špatné heslo – zůstane na rozcestníku s chybou');

	$response = PresenterTester::assertRedirect($tester->submitForm('Public:Homepage', 'signInForm', [
		'email' => 'zastupitel@obec.cz',
		'password' => Fixtures::Password,
	]));
	Assert::contains('/admin', $response->getUrl());
});
