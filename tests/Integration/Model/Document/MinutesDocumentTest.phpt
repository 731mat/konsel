<?php

declare(strict_types=1);

use App\Model\Document\MeetingDocumentFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Meeting\Command\AssignOfficials;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\ProceedingsFacade;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('minutes contain everything § 95 requires', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Zastupitelstvo obce schvaluje rozpočet na rok 2027 jako vyrovnaný.']);
	$meetingId = $scenario->meeting->meeting->getId();
	[$chair, $recorder, $first, $second] = $scenario->members;
	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->assignOfficials($meetingId, new AssignOfficials($chair->getId(), $recorder->getId(), $first->getId(), $second->getId()));
	$proceedings = $container->getByType(ProceedingsFacade::class);
	$proceedings->recordDiscussion($scenario->meeting->item->getId(), 'Paní Černá se ptala na rezervu. Starosta odpověděl.');
	$proceedings->recordMinutesDetails($meetingId, 4, 'Zasedání bylo veřejné.');
	$documents = $container->getByType(MeetingDocumentFacade::class);

	$draft = $documents->renderHtml($meetings->get($meetingId), MeetingDocumentType::Minutes);
	Assert::contains('KONCEPT', $draft, 'během zasedání je zápis koncept');

	$meetings->transition($meetingId, MeetingTransition::Close);
	$html = (string) preg_replace('~\s+~u', ' ', $documents->renderHtml($meetings->get($meetingId), MeetingDocumentType::Minutes));
	Assert::notContains('KONCEPT', $html);
	foreach ([
		'z 1. zasedání Zastupitelstva obce Neplachovice',
		'Přítomno <strong>5 z 5</strong> členů',
		'je usnášeníschopné',
		'Přítomno hostů: 4.',
		'Paní Černá se ptala na rezervu.',
		'<strong>Usnesení č. 1/2026</strong><br> Zastupitelstvo obce schvaluje rozpočet na rok 2027 jako vyrovnaný.',
		'Výsledek hlasování: pro 5, proti 0, zdržel se 0',
		'<strong>přijato</strong>',
		'Zasedání bylo veřejné.',
		'Zápis byl vyhotoven dne 16. 11. 2026',
		'ověřovatel zápisu',
		'zapisovatel',
		'předsedající',
	] as $expected) {
		Assert::contains($expected, $html, $expected);
	}

	Assert::notContains('Pro: Člen Dvořák', $html);
	$named = $documents->renderHtml($meetings->get($meetingId), MeetingDocumentType::Minutes, namedVotes: true);
	Assert::contains('Pro: Člen Černá, Člen Dvořák, Člen Novotný, Člen Svobodová, Člen Veselý.', $named, 'jmenovité hlasování na přání, česká abeceda (Č před D)');

	$pdf = $documents->generate($meetingId, MeetingDocumentType::Minutes);
	Assert::true(str_starts_with($pdf->content, '%PDF'));
	Assert::match('zapis-1-zasedani-2026-12-09.pdf', $pdf->fileName);

	$doc = $documents->generateEditable($meetingId, MeetingDocumentType::Minutes);
	Assert::same('application/msword', $doc->mimeType);
	Assert::match('zapis-%a%.doc', $doc->fileName);
	Assert::contains('<h1>Zápis</h1>', $doc->content);

	$meeting = $meetings->get($meetingId);
	Assert::same($meeting->getEndedAt()?->setTime(0, 0)->modify('+10 days')->format('Y-m-d'), $meeting->getMinutesDeadline()?->format('Y-m-d'));
	$meetings->transition($meetingId, MeetingTransition::ApproveMinutes);
	Assert::null($meetings->get($meetingId)->getMinutesDeadline(), 'ověřený zápis už lhůtu nehlídá');
});
