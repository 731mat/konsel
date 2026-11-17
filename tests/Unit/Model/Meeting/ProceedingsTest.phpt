<?php

declare(strict_types=1);

use App\Model\Meeting\AgendaItemContent;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\Term;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

function proceedingsMeeting(): Meeting
{
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	$council = new Body(new Term($municipality, '2026–2030', new DateTimeImmutable('2026-10-01')), BodyType::Council, 'Zastupitelstvo obce Neplachovice', 5);
	$meeting = new Meeting($council, 3, MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům');
	$meeting->addAgendaItem(new AgendaItemContent('Zahájení'));
	$meeting->addAgendaItem(new AgendaItemContent('Schválení programu', null, 'Zastupitelstvo schvaluje program.'));
	$meeting->addAgendaItem(new AgendaItemContent('Závěr'));

	return $meeting;
}

test('discussion and minutes details are recorded from start until minutes are approved', function (): void {
	$meeting = proceedingsMeeting();
	$clock = new MockClock('2026-12-09 18:00', 'Europe/Prague');
	$item = $meeting->getAgendaItems()[1];
	Assert::exception(fn() => $meeting->recordDiscussion($item, 'Text'), InvalidStateException::class, '%a%po zahájení%a%');

	$meeting->share($clock);
	$meeting->start($clock);
	$meeting->recordDiscussion($item, "  Pan Novotný navrhl doplnit bod.\r\n\r\nNávrh přijat.  ");
	Assert::same("Pan Novotný navrhl doplnit bod.\n\nNávrh přijat.", $item->getDiscussion());
	$meeting->recordDiscussion($item, '   ');
	Assert::null($item->getDiscussion());
	$e = Assert::exception(fn() => $meeting->recordDiscussion($item, str_repeat('a', Meeting::MaxDiscussionLength + 1)), ValidationException::class);
	Assert::same('discussion', $e?->field);

	$meeting->recordMinutesDetails(12, 'Starosta přerušil zasedání na 10 minut.');
	Assert::same(12, $meeting->getGuestCount());
	$e = Assert::exception(fn() => $meeting->recordMinutesDetails(-1, null), ValidationException::class);
	Assert::same('guestCount', $e?->field);

	$meeting->close($clock);
	Assert::noError(fn() => $meeting->recordDiscussion($item, 'Oprava po ukončení.'));
	$meeting->approveMinutes();
	Assert::exception(fn() => $meeting->recordMinutesDetails(1, null), InvalidStateException::class, '%a%ověřen%a%');
});

test('program is locked during the meeting; an item can be added only as added during the meeting', function (): void {
	$meeting = proceedingsMeeting();
	$clock = new MockClock('2026-12-09 18:00', 'Europe/Prague');
	Assert::exception(fn() => $meeting->addAgendaItemDuringMeeting(new AgendaItemContent('Doplněný bod')), InvalidStateException::class, '%a%během zasedání%a%');

	$meeting->share($clock);
	$meeting->start($clock);
	Assert::exception(fn() => $meeting->addAgendaItem(new AgendaItemContent('Obyčejně')), InvalidStateException::class);

	$added = $meeting->addAgendaItemDuringMeeting(new AgendaItemContent('Příspěvek hasičům', null, 'Zastupitelstvo schvaluje příspěvek.'), 3);
	Assert::true($added->isAddedDuringMeeting());
	Assert::same(['Zahájení', 'Schválení programu', 'Příspěvek hasičům', 'Závěr'], array_map(static fn($i) => $i->getTitle(), $meeting->getAgendaItems()));
	Assert::same(4, $meeting->getAgendaItems()[3]->getPosition());
	Assert::false($meeting->getAgendaItems()[0]->isAddedDuringMeeting());
});
