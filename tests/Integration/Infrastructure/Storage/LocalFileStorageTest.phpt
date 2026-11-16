<?php

declare(strict_types=1);

use App\Application\Shared\Storage\FileStorage;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\ValueObject\StoredFile;
use Tester\Assert;
use Tests\Support\ContainerFactory;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return resource
 */
function streamOf(string $content)
{
	$stream = fopen('php://memory', 'r+b');
	assert($stream !== false);
	fwrite($stream, $content);
	rewind($stream);

	return $stream;
}

test('stored file can be read back with metadata', function (): void {
	$storage = ContainerFactory::create()->getByType(FileStorage::class);

	$file = $storage->store(streamOf('%PDF-1.4 test'), 'Smlouva o dílo.PDF', 'm1/attachments');

	Assert::match('~^m1/attachments/2026/11/[a-f0-9]{32}\.pdf$~', $file->path);
	Assert::same('Smlouva o dílo.PDF', $file->originalName);
	Assert::same('application/pdf', $file->mimeType);
	Assert::same(13, $file->size);
	Assert::same(hash('sha256', '%PDF-1.4 test'), $file->sha256);
	Assert::true($storage->exists($file));
	Assert::same('%PDF-1.4 test', stream_get_contents($storage->readStream($file)));
});

test('mime type is detected from content, not from file name', function (): void {
	$storage = ContainerFactory::create()->getByType(FileStorage::class);

	$file = $storage->store(streamOf('<?php echo 1;'), 'obrazek.jpg', 'm1/attachments');

	Assert::notSame('image/jpeg', $file->mimeType);
});

test('deleted file no longer exists', function (): void {
	$storage = ContainerFactory::create()->getByType(FileStorage::class);
	$file = $storage->store(streamOf('x'), 'a.txt', 'm1/tmp');

	$storage->delete($file);

	Assert::false($storage->exists($file));
	Assert::exception(fn() => $storage->getLocalPath($file), RuntimeException::class);
});

test('invalid namespace is rejected', function (): void {
	$storage = ContainerFactory::create()->getByType(FileStorage::class);

	Assert::exception(fn() => $storage->store(streamOf('x'), 'a.txt', '../etc'), ValidationException::class);
	Assert::exception(fn() => $storage->store(streamOf('x'), 'a.txt', '/abs'), ValidationException::class);
});

test('path traversal in stored path is impossible', function (): void {
	$sha = str_repeat('a', 64);

	Assert::exception(fn() => new StoredFile('../../etc/passwd', 'x', 'text/plain', 1, $sha), ValidationException::class);
	Assert::exception(fn() => new StoredFile('m1/../' . str_repeat('a', 32), 'x', 'text/plain', 1, $sha), ValidationException::class);
	Assert::exception(fn() => new StoredFile('/etc/' . str_repeat('a', 32), 'x', 'text/plain', 1, $sha), ValidationException::class);
});

test('original name is sanitized', function (): void {
	Assert::same('passwd', StoredFile::sanitizeName('../../etc/passwd'));
	Assert::same('soubor.pdf', StoredFile::sanitizeName('C:\\Users\\x\\soubor.pdf'));
	Assert::same('soubor', StoredFile::sanitizeName("\x00..."));
});
