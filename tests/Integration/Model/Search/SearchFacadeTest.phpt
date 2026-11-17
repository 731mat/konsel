<?php

declare(strict_types=1);

use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\MeetingFacade;
use App\Model\Search\SearchDocumentType;
use App\Model\Search\SearchFacade;
use App\Model\Search\SearchHit;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @param list<SearchHit> $hits
 * @return list<string>
 */
function hitTypes(array $hits): array
{
	return array_map(static fn(SearchHit $hit): string => $hit->type->value, $hits);
}

/**
 * Zasedání, kde se o smlouvě o dílo jednalo a hlasovalo (usnesení), plus jiná obec se stejným textem.
 *
 * @return array{SearchFacade, ResolutionScenario, Nette\DI\Container}
 */
function bootSearch(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create(
		$container,
		['Zastupitelstvo obce schvaluje uzavření smlouvy o dílo se společností Silnice Morava s.r.o.'],
		static fn(MeetingScenario $meeting) => $container->getByType(AgendaFacade::class)->updateItem($meeting->item->getId(), new SaveAgendaItem(
			'Smlouva o dílo – oprava místní komunikace',
			'Výběrové řízení vyhrála firma Silnice Morava s.r.o.',
		)),
	);

	$other = MeetingScenario::create($container, (new Fixtures($container))->municipality('Jiná obec'));
	$container->getByType(AgendaFacade::class)->updateItem($other->item->getId(), new SaveAgendaItem('Smlouva o dílo – chodník'));

	return [$container->getByType(SearchFacade::class), $scenario, $container];
}

test('finds the resolution with and without diacritics, only within the municipality', function (): void {
	[$search, $scenario] = bootSearch();
	$municipalityId = $scenario->meeting->municipality->getId();

	foreach (['smlouva o dílo', 'smlouva o dilo', 'SMLOUVA O DÍLO', 'smlouv dil'] as $text) {
		$result = $search->search($municipalityId, $text);
		Assert::same(2, $result->total, $text);
		Assert::equal(['agenda_item', 'resolution'], hitTypes($result->hits), $text);
	}

	$resolution = $search->search($municipalityId, 'smlouva o dilo', SearchDocumentType::Resolution)->hits[0];
	Assert::same($scenario->resolutions[0]->getId(), $resolution->targetId);
	$highlighted = array_values(array_map(static fn(array $segment): string => $segment[0], array_filter($resolution->title->segments, static fn(array $segment): bool => $segment[1])));
	Assert::same(['Smlouva', 'dílo'], $highlighted, 'zvýrazní se shody v původním tvaru s diakritikou');
	Assert::contains('Přijato', $resolution->context);
	Assert::contains('1. zasedání', $resolution->context);
});

test('index follows changes: rename, comments, deletion', function (): void {
	[$search, $scenario, $container] = bootSearch();
	$municipalityId = $scenario->meeting->municipality->getId();
	$userId = (new Fixtures($container))->user($scenario->meeting->municipality)->getId();
	$comments = $container->getByType(CommentFacade::class);

	$comment = $comments->addByUser($scenario->meeting->item->getId(), $userId, '<p>Kdo bude dělat <strong>technický dozor</strong> investora?</p>');
	$hit = $search->search($municipalityId, 'technicky dozor')->hits[0] ?? null;
	Assert::same(SearchDocumentType::ItemComment, $hit?->type);
	Assert::same($scenario->meeting->item->getId(), $hit?->targetId, 'komentář vede na bod');
	Assert::notContains('<strong>', $hit?->snippet->toPlainText() ?? '', 'HTML komentáře se indexuje jako text');

	$comments->deleteByUser($comment->getId(), $userId);
	Assert::same(0, $search->search($municipalityId, 'technicky dozor')->total, 'smazaný komentář se nehledá');

	// bod v přípravě jiného zasedání: přejmenování a smazání zasedání
	$another = $scenario->meeting->another($container);
	$agenda = $container->getByType(AgendaFacade::class);
	$agenda->updateItem($another->item->getId(), new SaveAgendaItem('Prodej pozemku 456/7'));
	Assert::same(1, $search->search($municipalityId, 'pozemku 456')->total);
	$agenda->updateItem($another->item->getId(), new SaveAgendaItem('Pronájem hřiště'));
	Assert::same(0, $search->search($municipalityId, 'pozemku 456')->total);
	Assert::same(1, $search->search($municipalityId, 'pronajem hriste')->total);

	$container->getByType(MeetingFacade::class)->delete($another->meeting->getId());
	Assert::same(0, $search->search($municipalityId, 'pronajem hriste')->total, 'smazání zasedání odstraní z indexu i jeho body');
	Assert::same(1, $search->search($municipalityId, 'zasedani', SearchDocumentType::Meeting)->total, 'zbylo jen první zasedání');
});

test('filters, paging, FTS syntax from user input and reindex', function (): void {
	[$search, $scenario] = bootSearch();
	$municipalityId = $scenario->meeting->municipality->getId();
	$bodyId = $scenario->meeting->meeting->getBody()->getId();

	Assert::same(2, $search->search($municipalityId, 'smlouva', bodyId: $bodyId, year: 2026)->total);
	Assert::same(0, $search->search($municipalityId, 'smlouva', year: 2025)->total);
	Assert::same(0, $search->search($municipalityId, 'smlouva', bodyId: $bodyId + 1000)->total);
	Assert::same([], $search->search($municipalityId, 'smlouva', page: 2)->hits);
	Assert::noError(fn() => $search->search($municipalityId, '"smlouva" OR NEAR( * ^ -dilo'));
	Assert::same(0, $search->search($municipalityId, '')->total);

	Assert::same(5, $search->reindex(), '2 zasedání, 2 body, 1 usnesení');
	Assert::same(2, $search->search($municipalityId, 'smlouva o dilo')->total, 'po přeindexování stejné výsledky');
});
