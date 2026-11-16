<?php

declare(strict_types=1);

namespace App\UI\Form;

use App\Model\Storage\UploadedFile;
use Nette\Http\FileUpload;

/**
 * Převod nahraných souborů z Nette formuláře na UploadedFile pro fasády (neúspěšná nahrání se přeskočí).
 */
final class FormUploads
{
	/**
	 * @param iterable<mixed> $uploads
	 * @return list<UploadedFile>
	 */
	public static function toUploadedFiles(iterable $uploads): array
	{
		$files = [];
		foreach ($uploads as $upload) {
			if ($upload instanceof FileUpload && $upload->isOk()) {
				$files[] = UploadedFile::fromPath($upload->getTemporaryFile(), $upload->getUntrustedName());
			}
		}

		return $files;
	}
}
