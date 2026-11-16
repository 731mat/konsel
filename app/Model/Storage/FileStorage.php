<?php

declare(strict_types=1);

namespace App\Model\Storage;

use App\Model\Shared\ValueObject\StoredFile;

/**
 * Úložiště souborů (přílohy, loga, vygenerované dokumenty).
 * Soubory se nikdy neservírují přímo z www – vždy přes presenter s kontrolou oprávnění.
 */
interface FileStorage
{
	/**
	 * @param resource $stream zdroj dat (čte se od aktuální pozice do konce)
	 * @param string $namespace logická složka, např. "m12/attachments" (jen [a-z0-9_-] a lomítka)
	 */
	public function store($stream, string $originalName, string $namespace): StoredFile;

	public function storeLocalFile(string $localPath, string $originalName, string $namespace): StoredFile;

	/**
	 * @return resource
	 */
	public function readStream(StoredFile $file);

	/**
	 * Absolutní cesta pro knihovny pracující se soubory (FileResponse, PhpWord).
	 */
	public function getLocalPath(StoredFile $file): string;

	public function exists(StoredFile $file): bool;

	public function delete(StoredFile $file): void;
}
