<?php

declare(strict_types=1);

use App\Model\Document\ArchivedDocumentOrigin;
use App\Model\Document\DocumentArchiveFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Storage\FileStorage;
use App\Model\Storage\UploadedFile;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return resource
 */
function memoryStream(string $content)
{
	$stream = fopen('php://memory', 'w+b');
	assert($stream !== false);
	fwrite($stream, $content);
	rewind($stream);

	return $stream;
}

test('archive stores a PDF version; draft versions are marked, deletion is allowed before approval', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Zastupitelstvo obce schvaluje rozpočet.']);
	$meetingId = $scenario->meeting->meeting->getId();
	$userId = (new Fixtures($container))->user($scenario->meeting->municipality)->getId();
	$archive = $container->getByType(DocumentArchiveFacade::class);
	$storage = $container->getByType(FileStorage::class);

	$invitation = $archive->archive($meetingId, MeetingDocumentType::Invitation, $userId, 'Vyvěšeno na úřední desce');
	$minutes = $archive->archive($meetingId, MeetingDocumentType::Minutes, $userId);

	Assert::same(ArchivedDocumentOrigin::Generated, $invitation->getOrigin());
	Assert::same('Vyvěšeno na úřední desce', $invitation->getNote());
	Assert::true($invitation->getFile()->isPdf());
	Assert::true($storage->exists($invitation->getFile()));
	Assert::match('pozvanka-1-zasedani-2026-12-09.pdf', $invitation->getFile()->originalName);
	Assert::true($minutes->isDraft(), 'zápis uložený během zasedání je koncept');
	Assert::false($invitation->isDraft());
	Assert::same([$minutes->getId(), $invitation->getId()], array_map(static fn($d) => $d->getId(), $archive->findByMeeting($meetingId)), 'od nejnovější');

	$archive->delete($invitation->getId());
	Assert::false($storage->exists($invitation->getFile()), 'smazání odstraní i soubor');
	Assert::count(1, $archive->findByMeeting($meetingId));
});

test('final versions only after approval; approved archive is append-only', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Zastupitelstvo obce schvaluje rozpočet.']);
	$meetingId = $scenario->meeting->meeting->getId();
	$archive = $container->getByType(DocumentArchiveFacade::class);
	$meetings = $container->getByType(MeetingFacade::class);
	$draft = $archive->archive($meetingId, MeetingDocumentType::Resolutions, null);

	Assert::exception(fn() => $archive->archiveFinal($meetingId, null), InvalidStateException::class, 'Konečná verze vzniká až po ověření zápisu.');
	Assert::false($archive->hasFinalVersion($meetingId));

	$meetings->transition($meetingId, MeetingTransition::Close);
	$meetings->transition($meetingId, MeetingTransition::ApproveMinutes);
	Assert::false($archive->hasFinalVersion($meetingId));

	$final = $archive->archiveFinal($meetingId, null);
	Assert::same([MeetingDocumentType::Minutes, MeetingDocumentType::Resolutions], array_map(static fn($d) => $d->getType(), $final));
	Assert::same(ArchivedDocumentOrigin::Final, $final[0]->getOrigin());
	Assert::true($archive->hasFinalVersion($meetingId));

	Assert::exception(fn() => $archive->delete($draft->getId()), InvalidStateException::class, 'Zápis je ověřen, archiv zasedání už nelze měnit.');
	Assert::exception(fn() => $archive->delete($final[0]->getId()), InvalidStateException::class);

	// podepsaný originál jde doplnit i po ověření
	$signed = $archive->upload($meetingId, MeetingDocumentType::Minutes, new UploadedFile(memoryStream("%PDF-1.4\n%%EOF\n"), 'zapis-podepsany.pdf'), 15, null, 'podepsáno ověřovateli');
	Assert::same(ArchivedDocumentOrigin::Uploaded, $signed->getOrigin());
	Assert::count(4, $archive->findByMeeting($meetingId));
});

test('upload accepts only real PDF', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Zastupitelstvo obce schvaluje rozpočet.']);
	$meetingId = $scenario->meeting->meeting->getId();
	$archive = $container->getByType(DocumentArchiveFacade::class);

	Assert::exception(
		fn() => $archive->upload($meetingId, MeetingDocumentType::Minutes, new UploadedFile(memoryStream('<html>'), 'zapis.html'), 6, null),
		ValidationException::class,
		'Podepsaný originál nahrajte jako PDF (sken).',
	);
	Assert::exception(
		fn() => $archive->upload($meetingId, MeetingDocumentType::Minutes, new UploadedFile(memoryStream('<html><script>alert(1)</script>'), 'zapis.pdf'), 31, null),
		ValidationException::class,
		'Soubor není PDF.',
	);
	Assert::same([], $archive->findByMeeting($meetingId));
});
