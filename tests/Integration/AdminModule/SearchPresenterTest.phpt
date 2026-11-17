<?php

declare(strict_types=1);

use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('search page highlights matches and links to the resolution and the agenda item', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create(
		$container,
		['Zastupitelstvo obce schvaluje smlouvu o dílo se společností <Silnice> Morava.'],
		static fn(MeetingScenario $meeting) => $container->getByType(AgendaFacade::class)->updateItem($meeting->item->getId(), new SaveAgendaItem('Smlouva o dílo – oprava komunikace')),
	);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->meeting->municipality, role: Role::Reader));

	$html = $tester->render('Admin:Search', 'default', [
		'q' => 'smlouva o dilo',
	]);
	Assert::contains('2 výsledky', $html);
	Assert::contains('<mark>Smlouva</mark>', $html);
	Assert::contains('<mark>dílo</mark>', $html);
	Assert::contains('&lt;Silnice&gt;', $html, 'text z databáze je escapovaný');
	Assert::contains('/admin/resolution/detail/' . $scenario->resolutions[0]->getId(), $html);
	Assert::contains('/admin/agenda/detail/' . $scenario->meeting->item->getId(), $html);
	Assert::contains('name="q" class="form-control form-control-sm" placeholder="Hledat…" value="smlouva o dilo"', $html, 'dotaz zůstane v horní liště');

	Assert::contains('se nic nenašlo', $tester->render('Admin:Search', 'default', [
		'q' => 'kanalizace',
	]));
	Assert::contains('Zadejte, co hledáte', $tester->render('Admin:Search'));
	Assert::contains('Usnesení', $tester->render('Admin:Search', 'default', [
		'q' => 'smlouva',
		'type' => 'resolution',
		'year' => '2026',
		'body' => 'neplatne',
	]));
});
