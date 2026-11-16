<?php

declare(strict_types=1);

namespace App\Model\Shared\ValueObject;

use App\Model\Shared\Exception\ValidationException;

/**
 * Soubor uložený ve FileStorage. `path` je relativní klíč v úložišti, nikdy absolutní cesta.
 * V entitách se ukládá do jednoho sloupce typu `stored_file` (JSON, viz Core\Doctrine\Type\StoredFileType).
 */
final readonly class StoredFile
{
	public const string PathPattern = '~^(?!.*\.\.)[a-z0-9_-]+(?:/[a-z0-9_-]+)*/[a-z0-9]{32}(?:\.[a-z0-9]{1,10})?$~';

	public string $path;

	public string $originalName;

	public string $mimeType;

	public int $size;

	public string $sha256;

	public function __construct(string $path, string $originalName, string $mimeType, int $size, string $sha256)
	{
		if (preg_match(self::PathPattern, $path) !== 1) {
			throw new ValidationException('Neplatná cesta k souboru v úložišti.');
		}

		if ($size < 0 || preg_match('~^[a-f0-9]{64}$~', $sha256) !== 1) {
			throw new ValidationException('Neplatná metadata souboru.');
		}

		$this->path = $path;
		$this->originalName = self::sanitizeName($originalName);
		$this->mimeType = mb_substr($mimeType, 0, 150);
		$this->size = $size;
		$this->sha256 = $sha256;
	}

	/**
	 * @param array<mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			path: is_string($data['path'] ?? null) ? $data['path'] : '',
			originalName: is_string($data['originalName'] ?? null) ? $data['originalName'] : '',
			mimeType: is_string($data['mimeType'] ?? null) ? $data['mimeType'] : '',
			size: is_int($data['size'] ?? null) ? $data['size'] : -1,
			sha256: is_string($data['sha256'] ?? null) ? $data['sha256'] : '',
		);
	}

	/**
	 * @return array{path: string, originalName: string, mimeType: string, size: int, sha256: string}
	 */
	public function toArray(): array
	{
		return [
			'path' => $this->path,
			'originalName' => $this->originalName,
			'mimeType' => $this->mimeType,
			'size' => $this->size,
			'sha256' => $this->sha256,
		];
	}

	public function getExtension(): string
	{
		return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
	}

	public function isImage(): bool
	{
		return str_starts_with($this->mimeType, 'image/');
	}

	public function isPdf(): bool
	{
		return $this->mimeType === 'application/pdf';
	}

	/**
	 * Název pro stažení: bez cesty a řídicích znaků, max. 255 znaků.
	 */
	public static function sanitizeName(string $name): string
	{
		$name = basename(str_replace('\\', '/', $name));
		$name = preg_replace('~[\x00-\x1F\x7F]~u', '', $name) ?? '';
		$name = trim($name, " .\t");

		return $name === '' ? 'soubor' : mb_substr($name, -255);
	}
}
