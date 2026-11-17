<?php

declare(strict_types=1);

use App\Model\Resolution\ResolutionTaskFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('resolution list filters, detail adds a task, task page highlights overdue tasks', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Zastupitelstvo schvaluje rozpočet.', 'Zastupitelstvo schvaluje smlouvu o dílo.']);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->meeting->municipality, 'editor@obec.cz', Role::Editor));

	$list = $tester->render('Admin:Resolution');
	Assert::contains('1/2026', $list);
	Assert::contains('2/2026', $list);
	$filtered = $tester->render('Admin:Resolution', 'default', [
		'q' => 'smlouvu',
	]);
	Assert::contains('2/2026', $filtered);
	Assert::notContains('>1/2026<', $filtered);

	$params = [
		'id' => $scenario->resolutions[1]->getId(),
	];
	Assert::contains('Usnesení č. 2/2026', $tester->render('Admin:Resolution', 'detail', $params));
	PresenterTester::assertRedirect($tester->submitForm('Admin:Resolution', 'taskForm', [
		'description' => 'Podepsat smlouvu o dílo',
		'assigneeId' => (string) $scenario->members[0]->getId(),
		'dueDate' => '2026-11-10',
	], action: 'detail', params: $params));
	Assert::count(1, $container->getByType(ResolutionTaskFacade::class)->findByResolution($scenario->resolutions[1]->getId()));

	$tasks = $tester->render('Admin:Task', 'default', [
		'filter' => 'overdue',
	]);
	Assert::contains('Podepsat smlouvu o dílo', $tasks);
	Assert::contains('(po termínu)', $tasks);
	Assert::contains('task-overdue', $tasks);
	Assert::contains('Podepsat smlouvu o dílo', $tester->render('Admin:Dashboard'), 'widget na přehledu');

	$e = Assert::exception(fn() => $tester->run('Admin:Resolution', 'detail', $params + [
		'do' => 'revokeForm-submit',
	], post: [
		'note' => 'X',
		'_submit' => '1',
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode(), 'revokovat smí jen správce');
});
