<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\Mail\Mailer;
use App\Model\Mail\MailException;
use App\Model\Mail\MailMessage;

/**
 * Mailer pro testy: zprávy si pamatuje, na vyžádání selže pro zvolené adresy.
 */
final class FakeMailer implements Mailer
{
	/**
	 * @var list<MailMessage>
	 */
	public array $sent = [];

	/**
	 * @var list<string>
	 */
	public array $failFor = [];

	public function send(MailMessage $message): void
	{
		if (in_array($message->to->value, $this->failFor, true)) {
			throw new MailException("Recipient {$message->to->value} rejected");
		}

		$this->sent[] = $message;
	}
}
