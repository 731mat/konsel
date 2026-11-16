<?php

declare(strict_types=1);

use App\Model\Attachment\AttachmentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Storage\FileStorage;
use App\Model\Storage\UploadedFile;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

function upload(string $name, string $content = '%PDF-1.4 obsah'): UploadedFile
{
	$stream = fopen('php://temp', 'w+b');
	assert($stream !== false);
	fwrite($stream, $content);
	rewind($stream);

	return new UploadedFile($stream, $name);
}

/**
 * @return array{AttachmentFacade, MeetingScenario, Nette\DI\Container}
 */
function bootAttachments(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);

	return [$container->getByType(AttachmentFacade::class), MeetingScenario::create($container), $container];
}

test('multiple files are uploaded and stored', function (): void {
	[$facade, $scenario, $container] = bootAttachments();

	$attachments = $facade->upload($scenario->item->getId(), [upload('rozpočet.pdf'), upload('tabulka.xlsx', 'PK data')], null, publicForMembers: false);

	Assert::same(['rozpočet.pdf', 'tabulka.xlsx'], array_map(static fn($a) => $a->getTitle(), $facade->findByAgendaItem($scenario->item->getId())));
	Assert::false($attachments[0]->isPublicForMembers());
	Assert::true($container->getByType(FileStorage::class)->exists($attachments[0]->getFile()));
	Assert::same('%PDF-1.4 obsah', file_get_contents($facade->getLocalPath($attachments[0])));
});

test('disallowed file type rejects the whole batch and stores nothing', function (): void {
	[$facade, $scenario] = bootAttachments();

	$e = Assert::exception(
		fn() => $facade->upload($scenario->item->getId(), [upload('ok.pdf'), upload('virus.php', '<?php echo 1;')], null),
		ValidationException::class,
		'%A%typ .php není povolený%A%',
	);
	Assert::same('files', $e?->field);
	Assert::same([], $facade->findByAgendaItem($scenario->item->getId()));
});

test('deleting attachment, agenda item or draft meeting removes files from storage', function (): void {
	[$facade, $scenario, $container] = bootAttachments();
	$storage = $container->getByType(FileStorage::class);

	[$first] = $facade->upload($scenario->item->getId(), [upload('a.pdf')], null);
	$facade->delete($first->getId());
	Assert::false($storage->exists($first->getFile()));

	[$second] = $facade->upload($scenario->item->getId(), [upload('b.pdf')], null);
	$container->getByType(AgendaFacade::class)->removeItem($scenario->item->getId());
	Assert::false($storage->exists($second->getFile()));

	$other = $scenario->another($container);
	[$third] = $facade->upload($other->item->getId(), [upload('c.pdf')], null);
	$container->getByType(MeetingFacade::class)->delete($other->meeting->getId());
	Assert::false($storage->exists($third->getFile()));
});

test('attachments are locked after minutes are approved', function (): void {
	[$facade, $scenario, $container] = bootAttachments();
	[$attachment] = $facade->upload($scenario->item->getId(), [upload('a.pdf')], null);
	$meetings = $container->getByType(MeetingFacade::class);
	foreach ([MeetingTransition::Share, MeetingTransition::Start, MeetingTransition::Close] as $transition) {
		$meetings->transition($scenario->meeting->getId(), $transition);
	}

	Assert::noError(fn() => $facade->upload($scenario->item->getId(), [upload('prezentace.pdf')], null));
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::ApproveMinutes);

	Assert::exception(fn() => $facade->upload($scenario->item->getId(), [upload('pozdě.pdf')], null), InvalidStateException::class);
	Assert::exception(fn() => $facade->delete($attachment->getId()), InvalidStateException::class);
});
