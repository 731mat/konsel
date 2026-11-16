<?php

declare(strict_types=1);

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\Ico;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('valid IČO is accepted and normalized', function (): void {
	Assert::same('00064581', (new Ico('00064581'))->value);
	Assert::same('25596641', (new Ico(' 255 966 41 '))->value);
	Assert::same('00064581', (new Ico('64581'))->value);
});

test('invalid checksum is rejected', function (): void {
	Assert::exception(fn() => new Ico('00064582'), ValidationException::class, 'IČO nemá platný kontrolní součet.');
});

test('non-numeric or too long input is rejected', function (): void {
	Assert::exception(fn() => new Ico('ABC'), ValidationException::class);
	Assert::exception(fn() => new Ico('123456789'), ValidationException::class);
});

test('tryFrom returns null for empty input', function (): void {
	Assert::null(Ico::tryFrom(null));
	Assert::null(Ico::tryFrom('  '));
	Assert::true(Ico::tryFrom('00064581')?->equals(new Ico('64581')));
});
