<?php

declare(strict_types=1);

namespace App\Model\Meeting;

enum VotingResult: string
{
	case Adopted = 'adopted';
	case Rejected = 'rejected';

	public function label(VotingType $type = VotingType::Proposal): string
	{
		return match ($this) {
			self::Adopted => $type === VotingType::Election ? 'zvolen' : 'přijato',
			self::Rejected => $type === VotingType::Election ? 'nezvolen' : 'nepřijato',
		};
	}

	public function tone(): string
	{
		return $this === self::Adopted ? 'success' : 'danger';
	}
}
