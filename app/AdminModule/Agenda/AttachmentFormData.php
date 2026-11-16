<?php

declare(strict_types=1);

namespace App\AdminModule\Agenda;

use Nette\Http\FileUpload;

final class AttachmentFormData
{
	/**
	 * @var array<FileUpload>
	 */
	public array $files;

	public bool $publicForMembers;
}
