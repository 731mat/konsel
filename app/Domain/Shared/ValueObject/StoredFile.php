<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\ValidationException;
use Doctrine\ORM\Mapping as ORM;

/**
 * Soubor uložený ve FileStorage. `path` je relativní klíč v úložišti, nikdy absolutní cesta.
 * Vkládá se do entit jako Doctrine embeddable (sloupce file_path, file_original_name…).
 */
#[ORM\Embeddable]
final readonly class StoredFile
{
	public const string PathPattern = '~^(?!.*\.\.)[a-z0-9_-]+(?:/[a-z0-9_-]+)*/[a-z0-9]{32}(?:\.[a-z0-9]{1,10})?$~';

	#[ORM\Column(length: 255)]
	public string $path;

	#[ORM\Column(length: 255)]
	public string $originalName;

	#[ORM\Column(length: 150)]
	public string $mimeType;

	#[ORM\Column(type: 'bigint')]
	public int $size;

	#[ORM\Column(length: 64)]
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
