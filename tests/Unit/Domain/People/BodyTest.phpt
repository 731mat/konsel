<?php

declare(strict_types=1);

use App\Domain\Municipality\Municipality;
use App\Domain\People\Body;
use App\Domain\People\BodyType;
use App\Domain\People\MemberFunction;
use App\Domain\People\Person;
use App\Domain\People\PersonName;
use App\Domain\People\Term;
use App\Domain\Shared\Exception\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

function d(string $date): DateTimeImmutable
{
	return new DateTimeImmutable($date);
}

/**
 * @return array{Municipality, Body}
 */
function council(int $seats = 3): array
{
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	$term = new Term($municipality, '2026–2030', d('2026-10-01'), d('2030-09-30'));

	return [$municipality, new Body($term, BodyType::Council, 'Zastupitelstvo obce Neplachovice', $seats)];
}

function person(Municipality $municipality, string $first, string $last): Person
{
	return new Person($municipality, new PersonName($first, $last));
}

test('members are listed by function and surname at given date', function (): void {
	[$m, $body] = council(3);
	$body->addMember(person($m, 'Petr', 'Zelený'), MemberFunction::Member, d('2026-10-15'));
	$body->addMember(person($m, 'Jan', 'Starosta'), MemberFunction::Mayor, d('2026-10-15'));
	$body->addMember(person($m, 'Alena', 'Adamová'), MemberFunction::Member, d('2026-10-15'), d('2026-12-31'));

	$names = array_map(static fn($ms) => $ms->getPerson()->getName()->lastName, $body->getMembersAt(d('2026-11-01')));
	Assert::same(['Starosta', 'Adamová', 'Zelený'], $names);
	Assert::count(2, $body->getMembersAt(d('2027-01-01')));
});

test('person cannot have overlapping memberships in the same body', function (): void {
	[$m, $body] = council(5);
	$jan = person($m, 'Jan', 'Novák');
	$body->addMember($jan, MemberFunction::Member, d('2026-10-15'), d('2027-06-30'));

	Assert::exception(fn() => $body->addMember($jan, MemberFunction::DeputyMayor, d('2027-06-30')), ValidationException::class, '%A%už je v tomto období členem%A%');
	Assert::noError(fn() => $body->addMember($jan, MemberFunction::DeputyMayor, d('2027-07-01')));
});

test('mayor function is unique at any date', function (): void {
	[$m, $body] = council(5);
	$body->addMember(person($m, 'Jan', 'Starosta'), MemberFunction::Mayor, d('2026-10-15'));

	$e = Assert::exception(fn() => $body->addMember(person($m, 'Eva', 'Nová'), MemberFunction::Mayor, d('2026-11-01')), ValidationException::class);
	Assert::same('function', $e?->field);
	Assert::noError(fn() => $body->addMember(person($m, 'Eva', 'Nová'), MemberFunction::DeputyMayor, d('2026-11-01')));
	Assert::noError(fn() => $body->addMember(person($m, 'Karel', 'Druhý'), MemberFunction::DeputyMayor, d('2026-11-01')));
});

test('seat count cannot be exceeded, replacement is possible after ending membership', function (): void {
	[$m, $body] = council(2);
	$leaving = $body->addMember(person($m, 'A', 'Odchází'), MemberFunction::Member, d('2026-10-15'));
	$body->addMember(person($m, 'B', 'Zůstává'), MemberFunction::Member, d('2026-10-15'));

	Assert::exception(fn() => $body->addMember(person($m, 'C', 'Náhradník'), MemberFunction::Member, d('2027-03-01')), ValidationException::class, '%A%obsazena všechna místa%A%');

	$body->endMembership($leaving, d('2027-02-28'));
	Assert::noError(fn() => $body->addMember(person($m, 'C', 'Náhradník'), MemberFunction::Member, d('2027-03-01')));
	Assert::count(2, $body->getMembersAt(d('2027-03-01')));
});

test('future membership starting inside new interval is counted for seats', function (): void {
	[$m, $body] = council(1);
	$body->addMember(person($m, 'B', 'Budoucí'), MemberFunction::Member, d('2027-01-01'));

	Assert::exception(fn() => $body->addMember(person($m, 'A', 'Dřívější'), MemberFunction::Member, d('2026-11-01')), ValidationException::class);
	Assert::noError(fn() => $body->addMember(person($m, 'A', 'Dřívější'), MemberFunction::Member, d('2026-11-01'), d('2026-12-31')));
});

test('function must be allowed for body type and membership must start within term', function (): void {
	[$m, $body] = council(5);

	Assert::exception(fn() => $body->addMember(person($m, 'A', 'B'), MemberFunction::Chair, d('2026-10-15')), ValidationException::class);
	Assert::exception(fn() => $body->addMember(person($m, 'A', 'B'), MemberFunction::Member, d('2026-09-01')), ValidationException::class, '%A%volebního období%A%');
	Assert::exception(fn() => $body->addMember(person($m, 'A', 'B'), MemberFunction::Member, d('2026-11-01'), d('2026-10-01')), ValidationException::class);
});

test('person from other municipality cannot become member', function (): void {
	[, $body] = council(5);
	$other = new Municipality('Jiná', 'jina');

	Assert::exception(fn() => $body->addMember(person($other, 'A', 'B'), MemberFunction::Member, d('2026-10-15')), ValidationException::class, 'Osoba patří k jiné obci.');
});

test('seat count cannot drop below current members', function (): void {
	[$m, $body] = council(3);
	$body->addMember(person($m, 'A', 'A'), MemberFunction::Member, d('2026-10-15'));
	$body->addMember(person($m, 'B', 'B'), MemberFunction::Member, d('2026-10-15'));

	Assert::exception(fn() => $body->changeSeatCount(1), ValidationException::class);
	Assert::noError(fn() => $body->changeSeatCount(2));
});

test('person name formatting', function (): void {
	$name = new PersonName(' Jan ', 'Novák', 'Ing.', 'Ph.D.');

	Assert::same('Ing. Jan Novák, Ph.D.', $name->full());
	Assert::same('Novák Jan', $name->sortable());
	Assert::same('Jan Novák', (new PersonName('Jan', 'Novák', ' ', ''))->full());
});
