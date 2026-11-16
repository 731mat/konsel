<?php

declare(strict_types=1);

namespace App\PortalModule\Meeting;

final class CommentFormData
{
	public ?string $parentId = null;

	public ?string $body = null;

	/**
	 * @var array<\Nette\Http\FileUpload>
	 */
	public array $files = [];
}
