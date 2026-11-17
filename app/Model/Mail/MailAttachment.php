<?php

declare(strict_types=1);

namespace App\Model\Mail;

final readonly class MailAttachment
{
	public function __construct(
		public string $fileName,
		public string $content,
		public string $mimeType,
	) {}
}
