<?php

declare(strict_types=1);

namespace App\Model\People;

enum BodyType: string
{
	case Council = 'council';
	case Board = 'board';
	case Committee = 'committee';

	public function label(): string
	{
		return match ($this) {
			self::Council => 'Zastupitelstvo',
			self::Board => 'Rada',
			self::Committee => 'Výbor',
		};
	}

	/**
	 * Funkce, které lze v orgánu tohoto typu zastávat.
	 *
	 * @return list<MemberFunction>
	 */
	public function allowedFunctions(): array
	{
		return match ($this) {
			self::Council => [MemberFunction::Mayor, MemberFunction::DeputyMayor, MemberFunction::Member],
			self::Board => [MemberFunction::Mayor, MemberFunction::DeputyMayor, MemberFunction::BoardMember],
			self::Committee => [MemberFunction::Chair, MemberFunction::Member, MemberFunction::Secretary],
		};
	}
}
