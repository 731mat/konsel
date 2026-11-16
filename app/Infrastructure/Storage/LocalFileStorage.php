<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Shared\Storage\FileStorage;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\ValueObject\StoredFile;
use Nette\Utils\FileSystem;
use Psr\Clock\ClockInterface;

/**
 * Úložiště na lokálním disku: {root}/{namespace}/{yyyy}/{mm}/{32 hex}.{ext}
 * Zápis přes dočasný soubor + rename (atomicky), MIME typ podle obsahu.
 */
final readonly class LocalFileStorage implements FileStorage
{
	private const string NamespacePattern = '~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~';

	public function __construct(
		private string $rootDir,
		private ClockInterface $clock,
	) {}

	public function store($stream, string $originalName, string $namespace): StoredFile
	{
		if (!is_resource($stream)) {
			throw new \InvalidArgumentException('Stream must be a resource.');
		}

		if (preg_match(self::NamespacePattern, $namespace) !== 1) {
			throw new ValidationException("Neplatný jmenný prostor úložiště „{$namespace}“.");
		}

		$now = $this->clock->now();
		$extension = self::extension($originalName);
		$relativePath = sprintf('%s/%s/%s/%s%s', $namespace, $now->format('Y'), $now->format('m'), bin2hex(random_bytes(16)), $extension === '' ? '' : ".{$extension}");
		$target = $this->rootDir . '/' . $relativePath;
		FileSystem::createDir(dirname($target));

		$temp = $target . '.part';
		$out = fopen($temp, 'xb');
		if ($out === false) {
			throw new \RuntimeException("Unable to create '{$temp}'.");
		}

		$hash = hash_init('sha256');
		try {
			while (!feof($stream)) {
				$chunk = fread($stream, 1024 * 1024);
				if ($chunk === false) {
					throw new \RuntimeException('Unable to read input stream.');
				}

				hash_update($hash, $chunk);
				if (fwrite($out, $chunk) !== strlen($chunk)) {
					throw new \RuntimeException("Unable to write '{$temp}'.");
				}
			}
		} catch (\Throwable $e) {
			fclose($out);
			@unlink($temp);

			throw $e;
		}

		fclose($out);
		FileSystem::rename($temp, $target);

		return new StoredFile(
			path: $relativePath,
			originalName: $originalName,
			mimeType: self::detectMimeType($target),
			size: (int) filesize($target),
			sha256: hash_final($hash),
		);
	}

	public function storeLocalFile(string $localPath, string $originalName, string $namespace): StoredFile
	{
		$stream = fopen($localPath, 'rb');
		if ($stream === false) {
			throw new \RuntimeException("Unable to open '{$localPath}'.");
		}

		try {
			return $this->store($stream, $originalName, $namespace);
		} finally {
			fclose($stream);
		}
	}

	public function readStream(StoredFile $file)
	{
		$stream = fopen($this->getLocalPath($file), 'rb');
		if ($stream === false) {
			throw new \RuntimeException("Unable to read stored file '{$file->path}'.");
		}

		return $stream;
	}

	public function getLocalPath(StoredFile $file): string
	{
		// StoredFile::PathPattern vylučuje "..", absolutní cesty i neplatné znaky.
		$path = $this->rootDir . '/' . $file->path;
		if (!is_file($path)) {
			throw new \RuntimeException("Stored file '{$file->path}' does not exist.");
		}

		return $path;
	}

	public function exists(StoredFile $file): bool
	{
		return is_file($this->rootDir . '/' . $file->path);
	}

	public function delete(StoredFile $file): void
	{
		$path = $this->rootDir . '/' . $file->path;
		if (is_file($path)) {
			FileSystem::delete($path);
		}
	}

	private static function extension(string $originalName): string
	{
		$extension = strtolower(pathinfo(StoredFile::sanitizeName($originalName), PATHINFO_EXTENSION));

		return preg_match('~^[a-z0-9]{1,10}$~', $extension) === 1 ? $extension : '';
	}

	private static function detectMimeType(string $path): string
	{
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

		return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
	}
}
