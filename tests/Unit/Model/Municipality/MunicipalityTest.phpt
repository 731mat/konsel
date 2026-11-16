<?php

declare(strict_types=1);

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\Ico;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('municipality is created with trimmed name', function (): void {
	$municipality = new Municipality('  Neplachovice ', 'neplachovice', new Ico('00064581'));

	Assert::same('Neplachovice', $municipality->getName());
	Assert::same('neplachovice', $municipality->getSlug());
	Assert::same('00064581', $municipality->getIco()?->value);
	Assert::false($municipality->isPersisted());
});

test('empty name is rejected', function (): void {
	$e = Assert::exception(fn() => new Municipality('  ', 'x'), ValidationException::class);
	Assert::same('name', $e?->field);
});

test('invalid slug is rejected', function (): void {
	Assert::exception(fn() => new Municipality('Obec', 'Neplachovice'), ValidationException::class);
	Assert::exception(fn() => new Municipality('Obec', 'nepla--chovice'), ValidationException::class);
	Assert::exception(fn() => new Municipality('Obec', 'neplachovice-'), ValidationException::class);
});

test('unpersisted entity has no id', function (): void {
	Assert::exception(fn() => (new Municipality('Obec', 'obec'))->getId(), LogicException::class);
});
