<?php

declare(strict_types=1);

use App\Model\Attachment\AttachmentFacade;
use App\Model\Comment\CommentFacade;
use App\Model\Document\DocumentArchiveFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AttendanceStatus;
use App\Model\Meeting\Command\AssignOfficials;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingStatus;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\MeetingType;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Meeting\VotingFacade;
use App\Model\Meeting\VotingType;
use App\Model\Shared\Exception\DomainException;
use App\Model\Storage\UploadedFile;
use App\Model\Template\TemplateFacade;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return resource
 */
function pdfStream()
{
	$stream = fopen('php://memory', 'w+b');
	assert($stream !== false);
	fwrite($stream, "%PDF-1.4\n%%EOF\n");
	rewind($stream);

	return $stream;
}

test('approved meeting cannot be changed through any facade; reopening with a reason unlocks it', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Zastupitelstvo schvaluje rozpočet na rok 2027.']);
	$meetingId = $scenario->meeting->meeting->getId();
	$itemId = $scenario->meeting->item->getId();
	[$chair, , $first, $second] = $scenario->members;
	$admin = (new Fixtures($container))->user($scenario->meeting->municipality, 'spravce@neplachovice.cz', Role::Admin);

	$meetings = $container->getByType(MeetingFacade::class);
	$agenda = $container->getByType(AgendaFacade::class);
	$attachments = $container->getByType(AttachmentFacade::class);
	$comments = $container->getByType(CommentFacade::class);
	$proceedings = $container->getByType(ProceedingsFacade::class);
	$voting = $container->getByType(VotingFacade::class);
	$archive = $container->getByType(DocumentArchiveFacade::class);

	// stav před ověřením: příloha, komentář, diskuse, konečné dokumenty
	[$attachment] = $attachments->upload($itemId, [new UploadedFile(pdfStream(), 'rozpocet.pdf')], $admin->getId());
	$comment = $comments->addByUser($itemId, $admin->getId(), 'Rozpočet je vyrovnaný.');
	$proceedings->recordDiscussion($itemId, 'Bez připomínek.');
	$meetings->transition($meetingId, MeetingTransition::Close);
	$meetings->transition($meetingId, MeetingTransition::ApproveMinutes);
	[$finalMinutes] = $archive->archiveFinal($meetingId, $admin->getId());
	$roundId = $voting->findByMeeting($meetingId)[$itemId][0]->getId();
	$templateId = $container->getByType(TemplateFacade::class)->saveProgramAsTemplate($meetingId, 'Program rozpočtu')->getId();

	$attempts = [
		'změna termínu' => fn() => $meetings->update($meetingId, new SaveMeeting(MeetingType::Regular, new DateTimeImmutable('2026-12-10 18:00'), 'Sál')),
		'funkce' => fn() => $meetings->assignOfficials($meetingId, new AssignOfficials($chair->getId(), null, $first->getId(), $second->getId())),
		'oprava časů' => fn() => $meetings->correctTimes($meetingId, new DateTimeImmutable('2026-11-16 08:00'), new DateTimeImmutable('2026-11-16 09:00')),
		'smazání zasedání' => fn() => $meetings->delete($meetingId),
		'zahájení' => fn() => $meetings->transition($meetingId, MeetingTransition::Start),
		'nový bod' => fn() => $agenda->addItem($meetingId, new SaveAgendaItem('Různé')),
		'bod během zasedání' => fn() => $agenda->addItemDuringMeeting($meetingId, new SaveAgendaItem('Různé')),
		'úprava bodu' => fn() => $agenda->updateItem($itemId, new SaveAgendaItem('Jiný název')),
		'smazání bodu' => fn() => $agenda->removeItem($itemId),
		'přesun bodu' => fn() => $agenda->moveItem($itemId, 1),
		'nové pořadí' => fn() => $agenda->reorder($meetingId, [$itemId]),
		'standardní body' => fn() => $agenda->insertStandardItems($meetingId),
		'šablona programu' => fn() => $container->getByType(TemplateFacade::class)->applyToMeeting($templateId, $meetingId),
		'nahrání přílohy' => fn() => $attachments->upload($itemId, [new UploadedFile(pdfStream(), 'dodatek.pdf')], $admin->getId()),
		'smazání přílohy' => fn() => $attachments->delete($attachment->getId()),
		'skrytí přílohy' => fn() => $attachments->changeVisibility($attachment->getId(), false),
		'nový komentář' => fn() => $comments->addByUser($itemId, $admin->getId(), 'Dodatek'),
		'komentář zastupitele' => fn() => $comments->addByPerson($itemId, $first->getId(), 'Dodatek'),
		'smazání komentáře' => fn() => $comments->deleteByUser($comment->getId(), $admin->getId(), moderator: true),
		'docházka' => fn() => $proceedings->mark($meetingId, $first->getId(), AttendanceStatus::Excused),
		'všichni přítomni' => fn() => $proceedings->markAllPresent($meetingId),
		'odchod' => fn() => $proceedings->leave($meetingId, $first->getId()),
		'návrat' => fn() => $proceedings->return($meetingId, $first->getId()),
		'diskuse do zápisu' => fn() => $proceedings->recordDiscussion($itemId, 'Přepsáno'),
		'údaje do zápisu' => fn() => $proceedings->recordMinutesDetails($meetingId, 3, 'Poznámka'),
		'nové hlasování' => fn() => $voting->open($itemId, VotingType::CounterProposal, 'Protinávrh'),
		'oprava hlasování' => fn() => $voting->reopen($roundId),
		'smazání hlasování' => fn() => $voting->delete($roundId),
		'smazání z archivu' => fn() => $archive->delete($finalMinutes->getId()),
	];

	foreach ($attempts as $name => $attempt) {
		try {
			$attempt();
			Assert::fail("{$name}: zasedání s ověřeným zápisem šlo změnit");
		} catch (DomainException) {
			// očekávané – doménová pravidla změnu odmítla
		}
	}

	$meeting = $meetings->get($meetingId);
	Assert::same(MeetingStatus::MinutesApproved, $meeting->getStatus());
	Assert::same('Rozpočet na rok 2027', $meeting->getAgendaItems()[0]->getTitle());
	Assert::same('Bez připomínek.', $meeting->getAgendaItems()[0]->getDiscussion());
	Assert::count(1, $meeting->getAgendaItems());
	Assert::count(1, $attachments->findByAgendaItem($itemId));
	Assert::true($attachments->get($attachment->getId())->isPublicForMembers());
	Assert::false($comments->get($comment->getId())->isDeleted());
	Assert::count(1, $voting->findByMeeting($meetingId)[$itemId]);
	Assert::count(5, $proceedings->attendance($meetingId)->present());

	// znovuotevření s důvodem odemkne opravy průběhu; archiv konečných verzí zůstává
	$meetings->reopen($meetingId, $admin->getId(), 'Ověřovatel upozornil na chybu v diskusi.');
	Assert::same(MeetingStatus::Closed, $meetings->get($meetingId)->getStatus());
	$proceedings->recordDiscussion($itemId, 'Opraveno po upozornění ověřovatele.');
	Assert::exception(fn() => $archive->delete($finalMinutes->getId()), DomainException::class, 'Zápis je ověřen, archiv zasedání už nelze měnit.');
	Assert::same($admin->getName(), $meetings->get($meetingId)->getReopenings()[0]->getReopenedByName());
});
