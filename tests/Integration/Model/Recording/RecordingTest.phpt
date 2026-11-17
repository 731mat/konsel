<?php

declare(strict_types=1);

use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Recording\RecordingFacade;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Storage\FileStorage;
use App\Model\Storage\UploadedFile;
use App\Model\User\Role;
use Nette\Application\Responses\FileResponse;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

function audioFile(string $name, string $content = ''): UploadedFile
{
	$stream = fopen('php://memory', 'w+b');
	assert($stream !== false);
	fwrite($stream, $content !== '' ? $content : "ID3\x03\x00\x00\x00\x00\x00\x0a" . str_repeat("\x00", 10) . "\xFF\xFB\x90\x64" . str_repeat("\x00", 400));
	rewind($stream);

	return new UploadedFile($stream, $name);
}

test('several recordings can be uploaded, played and deleted until the minutes are approved', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$meetingId = $scenario->meeting->meeting->getId();
	$recordings = $container->getByType(RecordingFacade::class);
	$storage = $container->getByType(FileStorage::class);

	[$first, $second] = $recordings->upload($meetingId, [audioFile('zasedani-cast-1.mp3'), audioFile('zasedani-cast-2.mp3')], null);
	Assert::same('zasedani-cast-1', $first->getTitle());
	Assert::same('audio/mpeg', $first->getFile()->mimeType);
	Assert::count(2, $recordings->findByMeeting($meetingId));

	Assert::exception(fn() => $recordings->upload($meetingId, [audioFile('zapis.pdf', '%PDF-1.4')], null), ValidationException::class, 'Soubor „zapis.pdf“ není zvukový záznam (povolené jsou MP3, M4A, WAV, OGG, OPUS, FLAC…).');
	Assert::exception(fn() => $recordings->upload($meetingId, [audioFile('podvrh.mp3', '<html><script>alert(1)</script></html>')], null), ValidationException::class, 'Soubor „podvrh.mp3“ není zvukový záznam.');
	Assert::count(2, $recordings->findByMeeting($meetingId), 'odmítnutý soubor se neuloží');

	$recordings->delete($second->getId());
	Assert::false($storage->exists($second->getFile()));

	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->transition($meetingId, MeetingTransition::Close);
	$meetings->transition($meetingId, MeetingTransition::ApproveMinutes);
	Assert::exception(fn() => $recordings->delete($first->getId()), InvalidStateException::class, 'Zápis je ověřen, záznam zasedání už nelze smazat.');
	Assert::count(1, $recordings->upload($meetingId, [audioFile('doplnek.mp3')], null), 'doplnit záznam jde i po ověření');
});

test('meeting detail shows the player and streams the recording', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$meetingId = $scenario->meeting->meeting->getId();
	[$recording] = $container->getByType(RecordingFacade::class)->upload($meetingId, [audioFile('zasedani.mp3')], null);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->meeting->municipality, role: Role::Reader));

	$html = $tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
	]);
	Assert::contains('Záznam zasedání', $html);
	Assert::contains('<audio controls preload="none"', $html);
	Assert::notContains('Nahrát záznam zasedání', $html, 'čtenář nenahrává');

	$response = $tester->run('Admin:Meeting', 'recording', [
		'id' => $meetingId,
		'recordingId' => $recording->getId(),
	]);
	Assert::type(FileResponse::class, $response);
});
