<?php

declare(strict_types=1);

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\AgendaItemContent;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\Person;
use App\Model\People\PersonName;
use App\Model\People\Term;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

function agendaMeeting(?Municipality $municipality = null): Meeting
{
	$municipality ??= new Municipality('Neplachovice', 'neplachovice');
	$term = new Term($municipality, '2026–2030', new DateTimeImmutable('2026-10-01'));
	$council = new Body($term, BodyType::Council, 'Zastupitelstvo obce Neplachovice', 5);

	return new Meeting($council, 3, MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům');
}

/**
 * @return list<string>
 */
function titles(Meeting $meeting): array
{
	return array_map(static fn(AgendaItem $item): string => $item->getPosition() . ' ' . $item->getTitle(), $meeting->getAgendaItems());
}

test('items are appended, inserted, moved and removed with continuous numbering', function (): void {
	$meeting = agendaMeeting();
	$a = $meeting->addAgendaItem(new AgendaItemContent('A'));
	$b = $meeting->addAgendaItem(new AgendaItemContent('B'));
	$meeting->addAgendaItem(new AgendaItemContent('Úvod'), 1);
	$meeting->addAgendaItem(new AgendaItemContent('Konec'), 99);
	Assert::same(['1 Úvod', '2 A', '3 B', '4 Konec'], titles($meeting));

	$meeting->moveAgendaItem($b, 1);
	Assert::same(['1 B', '2 Úvod', '3 A', '4 Konec'], titles($meeting));
	$meeting->moveAgendaItem($b, 10);
	Assert::same(['1 Úvod', '2 A', '3 Konec', '4 B'], titles($meeting));

	$meeting->removeAgendaItem($a);
	Assert::same(['1 Úvod', '2 Konec', '3 B'], titles($meeting));
});

test('content is normalized and validated', function (): void {
	$content = new AgendaItemContent("  Rozpočtové \n opatření  ", "Řádek 1\r\nŘádek 2\r\n", '   ');

	Assert::same('Rozpočtové opatření', $content->title);
	Assert::same("Řádek 1\nŘádek 2", $content->description);
	Assert::null($content->proposedResolution);
	$e = Assert::exception(fn() => new AgendaItemContent('   '), ValidationException::class);
	Assert::same('title', $e?->field);
	$e = Assert::exception(fn() => new AgendaItemContent('X', proposedResolution: str_repeat('a', AgendaItemContent::MaxTextLength + 1)), ValidationException::class);
	Assert::same('proposedResolution', $e?->field);
});

test('rapporteur must belong to the municipality', function (): void {
	$meeting = agendaMeeting();
	$foreign = new Person(new Municipality('Jiná', 'jina'), new PersonName('Cizí', 'Osoba'));

	$e = Assert::exception(fn() => $meeting->addAgendaItem(new AgendaItemContent('X', rapporteur: $foreign)), ValidationException::class);
	Assert::same('rapporteur', $e?->field);
	Assert::same([], $meeting->getAgendaItems());
});

test('program is locked once the meeting starts', function (): void {
	$meeting = agendaMeeting();
	$item = $meeting->addAgendaItem(new AgendaItemContent('A'));
	$clock = new MockClock('2026-12-01 10:00');
	$meeting->share($clock);
	Assert::true($meeting->canEditProgram(), 'sdílený program lze ještě upravit');
	Assert::noError(fn() => $meeting->updateAgendaItem($item, new AgendaItemContent('A2')));

	$meeting->start($clock);
	Assert::false($meeting->canEditProgram());
	Assert::exception(fn() => $meeting->addAgendaItem(new AgendaItemContent('B')), InvalidStateException::class, 'Program lze měnit jen před zahájením zasedání.');
	Assert::exception(fn() => $meeting->updateAgendaItem($item, new AgendaItemContent('A3')), InvalidStateException::class);
	Assert::exception(fn() => $meeting->moveAgendaItem($item, 1), InvalidStateException::class);
	Assert::exception(fn() => $meeting->removeAgendaItem($item), InvalidStateException::class);
	Assert::same(['1 A2'], titles($meeting));
});

test('item of another meeting is rejected', function (): void {
	$meeting = agendaMeeting();
	$other = agendaMeeting();
	$foreignItem = $other->addAgendaItem(new AgendaItemContent('Cizí'));

	Assert::exception(fn() => $meeting->removeAgendaItem($foreignItem), InvalidStateException::class);
	Assert::exception(fn() => $meeting->moveAgendaItem($foreignItem, 1), InvalidStateException::class);
});
