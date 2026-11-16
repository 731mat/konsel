<?php

declare(strict_types=1);

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\Email;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('email is normalized to lowercase and trimmed', function (): void {
	Assert::same('starosta@neplachovice.cz', (new Email('  Starosta@Neplachovice.CZ '))->value);
});

test('invalid email is rejected', function (): void {
	Assert::exception(fn() => new Email('starosta'), ValidationException::class);
	Assert::exception(fn() => new Email(''), ValidationException::class);
	Assert::exception(fn() => new Email(str_repeat('a', 180) . '@x.cz'), ValidationException::class);
});
