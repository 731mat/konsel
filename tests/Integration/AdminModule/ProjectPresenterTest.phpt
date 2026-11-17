<?php

declare(strict_types=1);

use App\Model\Comment\CommentFacade;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectStatus;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('editor creates project, links agenda item, changes phase and comments publicly', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality));
	$projects = $container->getByType(ProjectFacade::class);

	PresenterTester::assertRedirect($tester->submitForm('Admin:Project', 'projectForm', [
		'title' => 'Oprava hasičské zbrojnice',
		'description' => 'Střecha zatéká.',
		'priority' => 'urgent',
		'responsible' => '',
		'estimatedCost' => '1200000',
	], action: 'add'));
	$project = $projects->find($scenario->municipality->getId())[0];
	$params = [
		'id' => $project->getId(),
	];

	PresenterTester::assertRedirect($tester->submitForm('Admin:Project', 'linkForm', [
		'item' => (string) $scenario->item->getId(),
	], action: 'detail', params: $params));
	PresenterTester::assertRedirect($tester->submitForm('Admin:Project', 'statusForm', [
		'status' => 'design',
		'note' => 'Zpracovává se projekt',
	], action: 'detail', params: $params));
	PresenterTester::assertRedirect($tester->submitForm('Admin:Project', 'commentForm', [
		'parentId' => '',
		'body' => 'Projektant dodá dokumentaci v březnu.',
		'publicForCitizens' => 'on',
	], action: 'detail', params: $params));

	Assert::same(ProjectStatus::Design, $projects->get($project->getId())->getStatus());
	Assert::true($container->getByType(CommentFacade::class)->findProjectThreads($project->getId())[0]->comment->isPublicForCitizens());

	$html = $tester->render('Admin:Project', 'detail', $params);
	Assert::contains('Rozpočet na rok 2027', $html, 'propojený bod ve vlákně');
	Assert::contains('Zpracovává se projekt', $html);
	Assert::contains('Projektant dodá dokumentaci v březnu.', $html);
	Assert::contains('vidí občané', $html);
	Assert::contains('Naléhavá priorita', $tester->render('Admin:Project'));
	Assert::contains('Plán: Oprava hasičské zbrojnice', $tester->render('Admin:Agenda', 'detail', [
		'id' => $scenario->item->getId(),
	]));
});

test('reader views project but cannot change it', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$project = $container->getByType(ProjectFacade::class)->create($scenario->municipality->getId(), new App\Model\Project\Command\SaveProject('Chodník'), 'Úřad');
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality, role: Role::Reader));

	$html = $tester->render('Admin:Project', 'detail', [
		'id' => $project->getId(),
	]);
	Assert::contains('Chodník', $html);
	Assert::notContains('Změnit fázi', $html);
	$e = Assert::exception(fn() => $tester->run('Admin:Project', 'add'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});
