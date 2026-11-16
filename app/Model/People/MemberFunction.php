<?php

declare(strict_types=1);

namespace App\Model\People;

enum MemberFunction: string
{
	case Mayor = 'mayor';
	case DeputyMayor = 'deputy_mayor';
	case BoardMember = 'board_member';
	case Chair = 'chair';
	case Secretary = 'secretary';
	case Member = 'member';

	public function label(): string
	{
		return match ($this) {
			self::Mayor => 'Starosta',
			self::DeputyMayor => 'Místostarosta',
			self::BoardMember => 'Radní',
			self::Chair => 'Předseda',
			self::Secretary => 'Tajemník výboru',
			self::Member => 'Člen',
		};
	}

	/**
	 * Funkci smí v orgánu k jednomu dni zastávat nejvýše jedna osoba.
	 */
	public function isUnique(): bool
	{
		return in_array($this, [self::Mayor, self::Chair, self::Secretary], true);
	}

	/**
	 * Řazení v seznamech členů (starosta první).
	 */
	public function rank(): int
	{
		return match ($this) {
			self::Mayor, self::Chair => 0,
			self::DeputyMayor => 1,
			self::BoardMember, self::Secretary => 2,
			self::Member => 3,
		};
	}
}
