<?php

declare(strict_types=1);

namespace App\Model\Meeting;

enum VoteChoice: string
{
	case For = 'for';
	case Against = 'against';
	case Abstain = 'abstain';
	case NotVoting = 'not_voting';

	public function label(): string
	{
		return match ($this) {
			self::For => 'pro',
			self::Against => 'proti',
			self::Abstain => 'zdržel se',
			self::NotVoting => 'nehlasoval',
		};
	}

	public function tone(): string
	{
		return match ($this) {
			self::For => 'success',
			self::Against => 'danger',
			self::Abstain => 'warning',
			self::NotVoting => 'secondary',
		};
	}

	/**
	 * Volby, které úřad zaklikává (nehlasoval = nic nevybráno).
	 *
	 * @return list<self>
	 */
	public static function choices(): array
	{
		return [self::For, self::Against, self::Abstain];
	}
}
