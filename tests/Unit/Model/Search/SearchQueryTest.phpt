<?php

declare(strict_types=1);

use App\Model\Search\HighlightedText;
use App\Model\Search\SearchDocumentType;
use App\Model\Search\SearchQuery;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('terms are lowercase words without punctuation, one-letter words and duplicates', function (): void {
	Assert::same(['smlouva', 'dílo'], (new SearchQuery(1, '  Smlouva o DÍLO, smlouva! '))->terms());
	Assert::same(['456'], (new SearchQuery(1, 'p. č. 456/7'))->terms(), 'číslo pozemku; jednoznakové části se vynechají');
	Assert::true((new SearchQuery(1, '" * - ( a v'))->isEmpty(), 'syntaxe FTS ani předložky nejsou slova');
	Assert::count(SearchQuery::MaxTerms, (new SearchQuery(1, implode(' ', range(10, 40))))->terms());
});

test('highlighted text splits marked matches into segments', function (): void {
	$text = HighlightedText::fromMarked('Smlouva ' . HighlightedText::Open . 'o dílo' . HighlightedText::Close . ' na <b>opravu</b>');

	Assert::same([['Smlouva ', false], ['o dílo', true], [' na <b>opravu</b>', false]], $text->segments);
	Assert::same('Smlouva o dílo na <b>opravu</b>', $text->toPlainText());
	Assert::same([], HighlightedText::fromMarked('')->segments);
});

test('row ids are unique per type', function (): void {
	Assert::same(3_000_000_012, SearchDocumentType::Resolution->rowId(12));
	Assert::count(count(SearchDocumentType::cases()), array_unique(array_map(static fn(SearchDocumentType $type): int => $type->code(), SearchDocumentType::cases())));
});
