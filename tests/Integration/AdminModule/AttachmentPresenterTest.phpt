<?php

declare(strict_types=1);

use App\Model\Attachment\AttachmentFacade;
use App\Model\Storage\UploadedFile;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Nette\Application\Responses\FileResponse;
use Nette\Http\FileUpload;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

function fileUpload(string $name, string $content): FileUpload
{
	$path = tempnam(sys_get_temp_dir(), 'upl');
	assert($path !== false);
	file_put_contents($path, $content);

	return new FileUpload([
		'name' => $name,
		'full_path' => $name,
		'type' => 'application/octet-stream',
		'size' => strlen($content),
		'tmp_name' => $path,
		'error' => UPLOAD_ERR_OK,
	]);
}

/**
 * @return array{PresenterTester, MeetingScenario, AttachmentFacade, Fixtures, Nette\DI\Container}
 */
function bootAttachmentUi(Role $role = Role::Editor): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$scenario = MeetingScenario::create($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($scenario->municipality, role: $role));

	return [$tester, $scenario, $container->getByType(AttachmentFacade::class), $fixtures, $container];
}

test('editor uploads several files through the form and downloads them', function (): void {
	[$tester, $scenario, $facade] = bootAttachmentUi();

	PresenterTester::assertRedirect($tester->submitForm('Admin:Agenda', 'attachmentForm', [
		'publicForMembers' => 'on',
	], action: 'detail', files: [
		'files' => [fileUpload('smlouva.pdf', '%PDF-1.4 smlouva'), fileUpload('mapa.png', "\x89PNG\r\n\x1a\nobrazek")],
	], params: [
		'id' => $scenario->item->getId(),
	]));

	$attachments = $facade->findByAgendaItem($scenario->item->getId());
	Assert::count(2, $attachments);
	$html = $tester->render('Admin:Agenda', 'detail', [
		'id' => $scenario->item->getId(),
	]);
	Assert::contains('smlouva.pdf', $html);
	Assert::contains('bi-file-earmark-pdf', $html);
	Assert::contains('2 přílohy', $tester->render('Admin:Meeting', 'detail', [
		'id' => $scenario->meeting->getId(),
	]));

	$response = $tester->run('Admin:Attachment', 'download', [
		'id' => $attachments[0]->getId(),
	]);
	Assert::type(FileResponse::class, $response);
	assert($response instanceof FileResponse);
	Assert::same('smlouva.pdf', $response->getName());
	Assert::same('%PDF-1.4 smlouva', file_get_contents($response->getFile()));
});

test('disallowed type is shown as form error', function (): void {
	[$tester, $scenario] = bootAttachmentUi();

	$html = PresenterTester::renderResponse($tester->submitForm('Admin:Agenda', 'attachmentForm', [], action: 'detail', files: [
		'files' => [fileUpload('skript.html', '<script>alert(1)</script>')],
	], params: [
		'id' => $scenario->item->getId(),
	]));

	Assert::contains('typ .html není povolený', $html);
});

test('reader can download but cannot upload or delete', function (): void {
	[$tester, $scenario, $facade] = bootAttachmentUi(Role::Reader);
	$stream = fopen('php://memory', 'w+b');
	assert($stream !== false);
	fwrite($stream, '%PDF');
	rewind($stream);
	[$attachment] = $facade->upload($scenario->item->getId(), [new UploadedFile($stream, 'a.pdf')], null);

	Assert::type(FileResponse::class, $tester->run('Admin:Attachment', 'download', [
		'id' => $attachment->getId(),
	]));
	Assert::notContains('Nahrát', $tester->render('Admin:Agenda', 'detail', [
		'id' => $scenario->item->getId(),
	]));
	$e = Assert::exception(fn() => $tester->run('Admin:Agenda', 'detail', [
		'id' => $scenario->item->getId(),
		'do' => 'deleteAttachment',
		'attachmentId' => $attachment->getId(),
	], post: [
		'x' => '1',
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});

test('attachment of another municipality returns 404', function (): void {
	[$tester, , $facade, $fixtures, $container] = bootAttachmentUi();
	$foreign = MeetingScenario::create($container, $fixtures->municipality('Cizí obec'));
	$stream = fopen('php://memory', 'w+b');
	assert($stream !== false);
	fwrite($stream, '%PDF');
	rewind($stream);
	[$attachment] = $facade->upload($foreign->item->getId(), [new UploadedFile($stream, 'cizi.pdf')], null);

	$tester->render('Admin:Dashboard');
	$e = Assert::exception(fn() => $tester->run('Admin:Attachment', 'download', [
		'id' => $attachment->getId(),
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
});
