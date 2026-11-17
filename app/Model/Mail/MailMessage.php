<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Shared\ValueObject\Email;

/**
 * E-mail k odeslání. Odesílatelská adresa je společná (parametr mailFrom), jméno odesílatele je obec.
 */
final readonly class MailMessage
{
	/**
	 * @param list<MailAttachment> $attachments
	 */
	public function __construct(
		public Email $to,
		public ?string $toName,
		public string $subject,
		public string $html,
		public ?string $fromName = null,
		public ?Email $replyTo = null,
		public array $attachments = [],
	) {}
}
