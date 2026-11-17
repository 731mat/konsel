<?php

declare(strict_types=1);

use App\Model\Municipality\BrandColors;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\HexColor;
use App\UI\Theme\BrandStyle;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('municipality uses default colors until it chooses its own', function (): void {
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	Assert::true($municipality->getBrandColors()->isDefault());
	Assert::false($municipality->hasCustomBrandColors());

	$municipality->changeBrandColors(BrandColors::fromStrings('#11457E', '#d7141a'));
	Assert::true($municipality->hasCustomBrandColors());
	Assert::same('#11457e', $municipality->getBrandColors()->primary->value);
	Assert::same('#d7141a', $municipality->getBrandColors()->accent->value);

	$municipality->changeBrandColors(BrandColors::default());
	Assert::false($municipality->hasCustomBrandColors(), 'výchozí barvy se neukládají – změna výchozího tématu se pak projeví všude');

	$municipality->changeBrandColors(BrandColors::fromStrings('#1e6b45', '#d9a441'));
	$municipality->changeBrandColors(null);
	Assert::true($municipality->getBrandColors()->isDefault());
});

test('too light primary color is rejected, accent may be anything', function (): void {
	$e = Assert::exception(fn() => BrandColors::fromStrings('#f5d76e', '#1b5e8c'), ValidationException::class, '%a%příliš světlá%a%');
	Assert::same('primaryColor', $e?->field);

	$e = Assert::exception(fn() => BrandColors::fromStrings('#1b5e8c', 'zlatá'), ValidationException::class);
	Assert::same('accentColor', $e?->field);

	Assert::noError(fn() => BrandColors::fromStrings('#1b5e8c', '#ffffff'));
});

test('brand style derives light and dark variables with readable text', function (): void {
	$css = BrandStyle::css(BrandColors::fromStrings('#11457e', '#d7141a'));

	Assert::contains(':root,[data-bs-theme=light]{--bs-primary:#11457e;--bs-primary-rgb:17, 69, 126;', $css);
	Assert::contains('--konsel-accent:#d7141a;', $css);
	Assert::contains('[data-bs-theme=dark]{--bs-primary-text-emphasis:', $css);
	Assert::notContains('<', $css);

	Assert::same('#ffffff', BrandStyle::onColor(new HexColor('#11457e'))->value);
	Assert::same('#111827', BrandStyle::onColor(new HexColor('#3b9ef5'))->value, 'na středně světlé barvě je čitelnější tmavý text');
});
