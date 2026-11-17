<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Access\IssuedToken;

/**
 * Výsledek rozeslání pozvánek. Odkazy z neodeslaných e-mailů se ukážou úřadu (v DB je jen hash).
 */
final readonly class InvitationResult
{
	/**
	 * @param list<IssuedToken> $sent
	 * @param list<IssuedToken> $withoutEmail osoba nemá e-mail
	 * @param list<IssuedToken> $failed e-mail se nepodařilo odeslat (chyba je v MailLog)
	 */
	public function __construct(
		public array $sent,
		public array $withoutEmail,
		public array $failed,
	) {}

	/**
	 * @return list<IssuedToken>
	 */
	public function notDelivered(): array
	{
		return [...$this->withoutEmail, ...$this->failed];
	}
}
