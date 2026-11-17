<?php

declare(strict_types=1);

use App\Model\Meeting\Quorum;
use App\Model\Shared\Exception\InvalidStateException;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('majority of all members is required', function (): void {
	foreach ([[1, 1], [2, 2], [3, 2], [5, 3], [7, 4], [9, 5], [10, 6], [15, 8], [21, 11]] as [$members, $required]) {
		Assert::same($required, (new Quorum($members, 0))->required, "{$members} členů");
	}
});

test('boundary: exactly half is not enough, half plus one is', function (): void {
	Assert::false((new Quorum(10, 5))->isReached());
	Assert::true((new Quorum(10, 6))->isReached());
	Assert::false((new Quorum(9, 4))->isReached());
	Assert::true((new Quorum(9, 5))->isReached());
	Assert::same(1, (new Quorum(9, 4))->missing());
	Assert::same(0, (new Quorum(9, 9))->missing());
});

test('invalid counts are rejected', function (): void {
	Assert::exception(fn() => new Quorum(0, 0), InvalidStateException::class);
	Assert::exception(fn() => new Quorum(5, 6), InvalidStateException::class);
	Assert::exception(fn() => new Quorum(5, -1), InvalidStateException::class);
});
