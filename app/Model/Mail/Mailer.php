<?php

declare(strict_types=1);

namespace App\Model\Mail;

/**
 * Odesílání e-mailů. Vývoj: SMTP do Mailpitu (http://localhost:8025), produkce: SMTP z local.neon.
 */
interface Mailer
{
	/**
	 * @throws MailException e-mail se nepodařilo odeslat
	 */
	public function send(MailMessage $message): void;
}
