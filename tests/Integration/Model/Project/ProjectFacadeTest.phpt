<?php

declare(strict_types=1);

use App\Model\Comment\CommentAuthorRef;
use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Project\Command\SaveProject;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectPriority;
use App\Model\Project\ProjectStatus;
use App\Model\Project\TimelineEntry;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Storage\UploadedFile;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('project keeps one thread across meetings: status changes, agenda items, files and comments', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$second = $scenario->another($container, '2027-01-20 18:00');
	$user = (new Fixtures($container))->user($scenario->municipality);
	$projects = $container->getByType(ProjectFacade::class);
	$comments = $container->getByType(CommentFacade::class);
	$clock = $container->getByType(ClockInterface::class);
	assert($clock instanceof MockClock);

	$project = $projects->create($scenario->municipality->getId(), new SaveProject('Výstavba mateřské školy', 'Kapacita 50 dětí.', ProjectPriority::High, estimatedCost: 45_000_000), 'Jana Nováková');
	$clock->modify('+1 hour');
	$comments->addToProject($project->getId(), CommentAuthorRef::user($user->getId()), 'Studie je objednaná.', publicForCitizens: true);
	$clock->modify('+1 hour');
	$projects->changeStatus($project->getId(), ProjectStatus::Design, 'Jana Nováková', 'Studie hotová');
	$stream = fopen('php://memory', 'w+b');
	assert($stream !== false);
	fwrite($stream, '%PDF');
	rewind($stream);
	$projects->uploadFiles($project->getId(), [new UploadedFile($stream, 'studie.pdf')], $user->getId());
	$comments->addToProject($project->getId(), CommentAuthorRef::user($user->getId()), 'Interní poznámka k rozpočtu.');

	$agenda = $container->getByType(AgendaFacade::class);
	$agenda->updateItem($scenario->item->getId(), new SaveAgendaItem('Schválení studie MŠ', proposedResolution: 'Zastupitelstvo schvaluje studii.'));
	$projects->linkAgendaItem($scenario->item->getId(), $project->getId());
	$projects->linkAgendaItem($second->item->getId(), $project->getId());

	$timeline = $projects->timeline($project->getId());
	$kinds = array_map(static fn(TimelineEntry $e): string => $e->kind, $timeline);
	Assert::same(['created', 'thread', 'status', 'file', 'thread', 'agenda', 'agenda'], $kinds);
	Assert::same('Schválení studie MŠ', $timeline[5]->agendaItem?->getTitle());
	Assert::same(ProjectStatus::Design, $timeline[2]->statusChange?->getToStatus());
	Assert::same('Studie hotová', $timeline[2]->statusChange?->getNote());

	$public = array_map(static fn(TimelineEntry $e): string => $e->kind, $projects->timeline($project->getId(), publicOnly: true));
	Assert::same(['created', 'thread', 'status', 'agenda', 'agenda'], $public, 'občan nevidí soubory úřadu ani interní komentáře');

	Assert::count(2, $projects->findLinkedItems($project->getId()));
	Assert::exception(fn() => $projects->changeStatus($project->getId(), ProjectStatus::Design, 'X'), InvalidStateException::class);
});

test('submitted citizen proposal cannot be published before review; validation', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$projects = $container->getByType(ProjectFacade::class);

	$proposal = $projects->create($scenario->municipality->getId(), new SaveProject('Lavičky v parku'), 'Občan Novák', ProjectStatus::Submitted);
	Assert::exception(fn() => $projects->setPublic($proposal->getId(), true), InvalidStateException::class);
	$projects->changeStatus($proposal->getId(), ProjectStatus::Idea, 'Úřad');
	$projects->setPublic($proposal->getId(), true);
	Assert::same([$proposal->getId()], array_map(static fn($p) => $p->getId(), $projects->findPublic($scenario->municipality->getId())));

	$e = Assert::exception(fn() => $projects->create($scenario->municipality->getId(), new SaveProject('  '), 'X'), ValidationException::class);
	Assert::same('title', $e?->field);
	Assert::exception(fn() => $projects->create($scenario->municipality->getId(), new SaveProject('X', estimatedCost: -5), 'X'), ValidationException::class);
});

test('comment visibility for citizens is allowed only in projects', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$user = (new Fixtures($container))->user($scenario->municipality);
	$comments = $container->getByType(CommentFacade::class);

	$itemComment = $comments->addByUser($scenario->item->getId(), $user->getId(), 'K bodu');
	Assert::exception(fn() => $comments->changeVisibility($itemComment->getId(), true), InvalidStateException::class);
});
