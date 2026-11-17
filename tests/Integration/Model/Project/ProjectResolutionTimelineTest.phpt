<?php

declare(strict_types=1);

use App\Model\Project\Command\SaveProject;
use App\Model\Project\ProjectFacade;
use App\Model\Project\TimelineEntry;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('project thread shows the adopted resolution of a linked agenda item – also to citizens', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Zastupitelstvo schvaluje projektovou dokumentaci mateřské školy.']);
	$projects = $container->getByType(ProjectFacade::class);
	$project = $projects->create($scenario->meeting->municipality->getId(), new SaveProject('Nová mateřská škola'), 'Úřad');
	$projects->linkAgendaItem($scenario->meeting->item->getId(), $project->getId());
	$projects->setPublic($project->getId(), true);

	$agenda = array_values(array_filter($projects->timeline($project->getId()), static fn(TimelineEntry $e): bool => $e->kind === TimelineEntry::Agenda));
	Assert::count(1, $agenda);
	Assert::same(['1/2026'], array_map(static fn($r) => $r->getNumber(), $agenda[0]->resolutions));

	$html = (new PresenterTester($container))->render('Public:Ideas', 'detail', [
		'municipality' => 'neplachovice',
		'id' => $project->getId(),
	]);
	Assert::contains('usnesení č. 1/2026', $html);
	Assert::contains('Zastupitelstvo schvaluje projektovou dokumentaci mateřské školy.', $html);
});
