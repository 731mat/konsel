<?php

declare(strict_types=1);

namespace App\AdminModule\Project;

final class FileFormData
{
	/**
	 * @var array<\Nette\Http\FileUpload>
	 */
	public array $files = [];
}
