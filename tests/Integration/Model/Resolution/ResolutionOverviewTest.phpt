<?php

declare(strict_types=1);

use App\Model\Resolution\Command\SaveResolutionTask;
use App\Model\Resolution\ResolutionFacade;
use App\Model\Resolution\ResolutionSearch;
use App\Model\Resolution\ResolutionStatus;
use App\Model\Resolution\ResolutionTaskFacade;
use App\Model\Resolution\TaskFilter;
use App\Model\Resolution\TaskStatus;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\Email;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('resolutions are found by text, number, year and status; revoked ones are marked', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, [
		'Zastupitelstvo schvaluje rozpočet na rok 2027.',
		'Zastupitelstvo schvaluje smlouvu o dílo se společností Silnice Morava.',
	]);
	$facade = $container->getByType(ResolutionFacade::class);
	$municipalityId = $scenario->meeting->municipality->getId();

	Assert::count(2, $facade->search($municipalityId, new ResolutionSearch()));
	Assert::same(['2/2026'], array_map(static fn($r) => $r->getNumber(), $facade->search($municipalityId, new ResolutionSearch(text: 'SILNICE'))));
	Assert::same(['1/2026'], array_map(static fn($r) => $r->getNumber(), $facade->search($municipalityId, new ResolutionSearch(text: '1/2026'))));
	Assert::count(2, $facade->search($municipalityId, new ResolutionSearch(year: 2026, bodyId: $scenario->meeting->meeting->getBody()->getId())));
	Assert::same([], $facade->search($municipalityId, new ResolutionSearch(year: 2025)));
	Assert::same([2026], $facade->findYears($municipalityId));

	$e = Assert::exception(fn() => $facade->revoke($scenario->resolutions[0]->getId(), '  '), ValidationException::class);
	Assert::same('note', $e?->field);
	$facade->revoke($scenario->resolutions[0]->getId(), 'Revokováno usnesením č. 5/2027.');
	Assert::same(['1/2026'], array_map(static fn($r) => $r->getNumber(), $facade->search($municipalityId, new ResolutionSearch(status: ResolutionStatus::Revoked))));
	Assert::exception(fn() => $facade->revoke($scenario->resolutions[0]->getId(), 'Znovu'), InvalidStateException::class);
});

test('tasks: mine by e-mail, overdue after the due date, completed ones leave the open list', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$tasks = $container->getByType(ResolutionTaskFacade::class);
	$municipalityId = $scenario->meeting->municipality->getId();
	$resolutionId = $scenario->resolutions[0]->getId();
	[$mayor, $deputy] = $scenario->members;

	$sign = $tasks->add($resolutionId, new SaveResolutionTask('Podepsat smlouvu o dílo', $mayor->getId(), new DateTimeImmutable('2026-11-15')));
	$tasks->add($resolutionId, new SaveResolutionTask('Zveřejnit smlouvu v registru', $deputy->getId(), new DateTimeImmutable('2026-11-16')));
	$tasks->add($resolutionId, new SaveResolutionTask('Kontrolní den stavby'));
	Assert::exception(fn() => $tasks->add($resolutionId, new SaveResolutionTask('   ')), ValidationException::class);

	Assert::same(['Podepsat smlouvu o dílo', 'Zveřejnit smlouvu v registru', 'Kontrolní den stavby'], array_map(static fn($t) => $t->getDescription(), $tasks->find($municipalityId, TaskFilter::Open)), 'podle termínu, bez termínu na konci');
	Assert::same(['Podepsat smlouvu o dílo'], array_map(static fn($t) => $t->getDescription(), $tasks->find($municipalityId, TaskFilter::Overdue)), 'testovací dnešek je 16. 11. – termín dnes ještě není po termínu');
	Assert::true($tasks->isOverdue($sign));
	Assert::same(['Zveřejnit smlouvu v registru'], array_map(static fn($t) => $t->getDescription(), $tasks->find($municipalityId, TaskFilter::Mine, new Email('svobodova@obec.cz'))));
	Assert::same([], $tasks->find($municipalityId, TaskFilter::Mine, null));

	$tasks->complete($sign->getId(), 'Podepsáno 14. 11.');
	Assert::same(TaskStatus::Done, $tasks->get($sign->getId())->getStatus());
	Assert::false($tasks->isOverdue($tasks->get($sign->getId())), 'splněný úkol není po termínu');
	Assert::count(2, $tasks->find($municipalityId, TaskFilter::Open));
	Assert::count(3, $tasks->find($municipalityId, TaskFilter::All));
	Assert::exception(fn() => $tasks->complete($sign->getId()), InvalidStateException::class);
	$tasks->reopen($sign->getId());
	Assert::count(3, $tasks->find($municipalityId, TaskFilter::Open));
});
