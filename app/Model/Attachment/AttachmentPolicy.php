<?php

declare(strict_types=1);

namespace App\Model\Attachment;

use App\Model\Shared\Exception\ValidationException;

/**
 * Které soubory lze nahrát: whitelist přípon (žádné skripty, HTML ani spustitelné soubory) a maximální velikost.
 */
final readonly class AttachmentPolicy
{
	public const array AllowedExtensions = [
		'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp',
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'zip', '7z', 'dwg', 'dxf', 'xml', 'isdoc',
		'mp3', 'm4a', 'wav', 'mp4', 'eml', 'msg',
	];

	public function __construct(
		public int $maxSize = 64 * 1024 * 1024,
	) {}

	/**
	 * @throws ValidationException
	 */
	public function assertAllowed(string $fileName, int $size): void
	{
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if (!in_array($extension, self::AllowedExtensions, true)) {
			throw new ValidationException("Soubor „{$fileName}“ nelze nahrát – typ .{$extension} není povolený. Povolené jsou dokumenty, tabulky, obrázky, PDF a archivy ZIP.", 'files');
		}

		if ($size > $this->maxSize) {
			throw new ValidationException(sprintf('Soubor „%s“ je příliš velký (max. %d MB).', $fileName, intdiv($this->maxSize, 1024 * 1024)), 'files');
		}

		if ($size === 0) {
			throw new ValidationException("Soubor „{$fileName}“ je prázdný.", 'files');
		}
	}
}
