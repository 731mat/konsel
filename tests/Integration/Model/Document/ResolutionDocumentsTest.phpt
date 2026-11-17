<?php

declare(strict_types=1);

use App\Model\Document\MeetingDocumentFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingStatus;
use App\Model\Meeting\MeetingTransition;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('resolutions of the meeting, attendance sheet and a single resolution extract', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Zastupitelstvo schvaluje rozpočet.', 'Zastupitelstvo schvaluje smlouvu o dílo.']);
	$meetingId = $scenario->meeting->meeting->getId();
	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->transition($meetingId, MeetingTransition::Close);
	$documents = $container->getByType(MeetingDocumentFacade::class);
	$meeting = $meetings->get($meetingId);

	$resolutions = (string) preg_replace('~\s+~u', ' ', $documents->renderHtml($meeting, MeetingDocumentType::Resolutions));
	Assert::contains('Usnesení č. 1/2026', $resolutions);
	Assert::contains('Usnesení č. 2/2026', $resolutions);
	Assert::contains('Zastupitelstvo schvaluje smlouvu o dílo.', $resolutions);
	Assert::contains('místostarosta', $resolutions);

	$sheet = $documents->renderHtml($meeting, MeetingDocumentType::AttendanceSheet);
	Assert::contains('Prezenční listina', $sheet);
	Assert::contains('Člen Černá', $sheet);
	Assert::same(5 + 12, substr_count($sheet, 'height: 22pt'), '5 členů + 12 řádků pro hosty');

	$extract = $documents->generateResolutionExtract($scenario->resolutions[1]->getId());
	Assert::true(str_starts_with($extract->content, '%PDF'));
	Assert::same('vypis-usneseni-2-2026.pdf', $extract->fileName);
	Assert::same('application/msword', $documents->generateResolutionExtract($scenario->resolutions[1]->getId(), editable: true)->mimeType);

	foreach ([MeetingDocumentType::Resolutions, MeetingDocumentType::AttendanceSheet] as $type) {
		Assert::true(str_starts_with($documents->generate($meetingId, $type)->content, '%PDF'), $type->value);
	}

	Assert::true(MeetingDocumentType::AttendanceSheet->isAvailableFor(MeetingStatus::Draft), 'prezenční listina se tiskne před zasedáním');
	Assert::false(MeetingDocumentType::Resolutions->isAvailableFor(MeetingStatus::Shared));

	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->meeting->municipality, role: Role::Editor));
	Assert::type(Nette\Application\Responses\CallbackResponse::class, $tester->run('Admin:Resolution', 'extract', [
		'id' => $scenario->resolutions[0]->getId(),
	]));
	Assert::contains('Výpis z usnesení', $tester->render('Admin:Resolution', 'detail', [
		'id' => $scenario->resolutions[0]->getId(),
	]));
});
