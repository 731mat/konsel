<?php

declare(strict_types=1);

namespace App\Model\Mail;

enum MailType: string
{
	case Invitation = 'invitation';
	case ProposalNotice = 'proposal_notice';

	public function label(): string
	{
		return match ($this) {
			self::Invitation => 'Pozvánka na zasedání',
			self::ProposalNotice => 'Upozornění na podnět občana',
		};
	}
}
