<?php

declare(strict_types=1);

namespace App\Model\Storage;

/**
 * Soubor nahraný uživatelem – nezávislé na Nette FileUpload (Application vrstva o HTTP neví).
 */
final readonly class UploadedFile
{
	/**
	 * @param resource $stream
	 */
	public function __construct(
		public mixed $stream,
		public string $originalName,
	) {}

	public static function fromPath(string $path, string $originalName): self
	{
		$stream = fopen($path, 'rb');
		if ($stream === false) {
			throw new \RuntimeException("Unable to open uploaded file '{$path}'.");
		}

		return new self($stream, $originalName);
	}
}
