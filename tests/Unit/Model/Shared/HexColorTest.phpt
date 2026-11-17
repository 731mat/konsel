<?php

declare(strict_types=1);

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\HexColor;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('color is normalized to lowercase #rrggbb', function (): void {
	Assert::same('#1b5e8c', (new HexColor(' #1B5E8C '))->value);
	Assert::same('#aabbcc', (new HexColor('abc'))->value);
	Assert::same([27, 94, 140], (new HexColor('#1b5e8c'))->rgb());
});

test('invalid color carries the form field', function (): void {
	$e = Assert::exception(fn() => new HexColor('modrá', 'primaryColor'), ValidationException::class, '%a%#RRGGBB%a%');
	Assert::same('primaryColor', $e?->field);
	Assert::exception(fn() => new HexColor('#12345g'), ValidationException::class);
	Assert::exception(fn() => new HexColor('red; } body { display:none'), ValidationException::class);
});

test('tint, shade and WCAG contrast', function (): void {
	$color = new HexColor('#1b5e8c');

	Assert::same('#ffffff', $color->tint(1)->value);
	Assert::same('#000000', $color->shade(1)->value);
	Assert::same('#d1dfe8', $color->tint(0.8)->value);
	Assert::same(21.0, round(HexColor::black()->contrastWith(HexColor::white()), 2));
	Assert::same(1.0, $color->contrastWith($color));
	Assert::true($color->contrastWith(HexColor::white()) > 4.5, 'výchozí modrá splní WCAG AA i pro běžný text');
});
