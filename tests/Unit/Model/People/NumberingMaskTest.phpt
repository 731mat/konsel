<?php

declare(strict_types=1);

use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\NumberingMask;
use App\Model\People\NumberingScope;
use App\Model\People\Term;
use App\Model\Shared\Exception\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

$date = new DateTimeImmutable('2026-12-09');

test('masks from the plan format correctly', function () use ($date): void {
	$cases = [
		['{n}/{yyyy}', '7/2026'],
		['ZO/{meeting}/{n}/{yy}', 'ZO/3/7/26'],
		['R-{n:3}/{yyyy}', 'R-007/2026'],
		['{body}-{item}.{n}', 'ZO-4.7'],
		['Usnesení č. {n}', 'Usnesení č. 7'],
		['{yyyy}/{n:2}', '2026/07'],
	];
	foreach ($cases as [$mask, $expected]) {
		Assert::same($expected, (new NumberingMask($mask))->format(7, $date, 3, 4, 'ZO'), $mask);
	}

	Assert::same('123/2026', (new NumberingMask('{n:2}/{yyyy}'))->format(123, $date, 3, 4, 'ZO'), 'širší číslo se nezkracuje');
	Assert::same('RO 7/26', (new NumberingMask('{body} {n}/{yy}'))->example('RO'));
	Assert::same('{n}/{yyyy}', (string) new NumberingMask('  {n}/{yyyy}  '));
});

test('invalid masks are rejected with a field for the form', function (): void {
	$invalid = [
		'' => 'musí mít 1 až',
		'2026' => 'právě jednou',
		'{n}/{n}' => 'právě jednou',
		'{x}/{n}' => 'Neznámá proměnná {x}',
		'{N}/{yyyy}' => 'neplatnou proměnnou',
		'{n}/{yyyy:2}' => 'jen u pořadového čísla',
		'{n:0}' => 'na 1 až 9 míst',
		'{n:10}' => 'na 1 až 9 míst',
		'{n}/{yyyy' => 'neuzavřenou',
		'{n}}' => 'neuzavřenou',
	];
	foreach ($invalid as $mask => $message) {
		$e = Assert::exception(fn() => new NumberingMask((string) $mask), ValidationException::class);
		Assert::contains($message, (string) $e?->getMessage(), (string) $mask);
		Assert::same('numberingMask', $e?->field);
	}

	Assert::exception(fn() => new NumberingMask('{n}' . str_repeat('x', 100)), ValidationException::class);
});

test('body code and numbering scope key', function () use ($date): void {
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	$term = new Term($municipality, '2026–2030', new DateTimeImmutable('2026-10-01'));
	$council = new Body($term, BodyType::Council, 'Zastupitelstvo obce Neplachovice', 9);
	Assert::same('ZO', $council->getCode());
	Assert::same('RO', (new Body($term, BodyType::Board, 'Rada obce', 5))->getCode());
	Assert::same('FV', (new Body($term, BodyType::Committee, 'Finanční výbor', 3))->getCode());
	Assert::same('KV', (new Body($term, BodyType::Committee, 'Kontrolní výbor obce', 3))->getCode());

	Assert::same('Y2026', $council->numberingScopeKey($date, 42));
	$council->changeNumberingMask('{n}/{meeting}', NumberingScope::Meeting);
	Assert::same('M42', $council->numberingScopeKey($date, 42));
	Assert::same('{n}/{meeting}', $council->getMask()->value);
	Assert::exception(fn() => $council->changeNumberingMask('bez čísla'), ValidationException::class);
	Assert::same(NumberingScope::Meeting, $council->getNumberingScope(), 'neplatná maska nic nezmění');
});
