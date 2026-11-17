<?php

declare(strict_types=1);

namespace App\Model\Meeting;

enum VotingType: string
{
	case Proposal = 'proposal';
	case CounterProposal = 'counter_proposal';
	case Amendment = 'amendment';
	case Election = 'election';

	public function label(): string
	{
		return match ($this) {
			self::Proposal => 'Návrh usnesení',
			self::CounterProposal => 'Protinávrh',
			self::Amendment => 'Pozměňovací návrh',
			self::Election => 'Volba osoby',
		};
	}

	/**
	 * Návrh a protinávrh se navzájem vylučují: po přijetí jednoho se o dalších nehlasuje.
	 */
	public function decidesItem(): bool
	{
		return $this === self::Proposal || $this === self::CounterProposal;
	}
}
