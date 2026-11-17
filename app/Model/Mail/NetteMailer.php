<?php

declare(strict_types=1);

namespace App\Model\Mail;

use Nette\Mail\Mailer as NetteMailerInterface;
use Nette\Mail\Message;
use Nette\Mail\SendException;

/**
 * Odesílání přes nette/mail (SMTP podle sekce mail: v konfiguraci).
 */
final readonly class NetteMailer implements Mailer
{
	public function __construct(
		private NetteMailerInterface $mailer,
		private string $from,
	) {}

	public function send(MailMessage $message): void
	{
		$mail = (new Message())
			->setFrom($this->from, $message->fromName)
			->addTo($message->to->value, $message->toName)
			->setSubject($message->subject)
			->setHtmlBody($message->html);

		if ($message->replyTo !== null) {
			$mail->addReplyTo($message->replyTo->value);
		}

		foreach ($message->attachments as $attachment) {
			$mail->addAttachment($attachment->fileName, $attachment->content, $attachment->mimeType);
		}

		try {
			$this->mailer->send($mail);
		} catch (SendException $e) {
			throw new MailException($e->getMessage(), 0, $e);
		}
	}
}
