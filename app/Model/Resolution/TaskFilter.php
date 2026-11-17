<?php

declare(strict_types=1);

namespace App\Model\Resolution;

/**
 * Výběr úkolů: všechny otevřené, jen moje (podle e-mailu osoby = e-mail uživatele), po termínu, nebo i vyřízené.
 */
enum TaskFilter: string
{
	case Open = 'open';
	case Mine = 'mine';
	case Overdue = 'overdue';
	case All = 'all';

	public function label(): string
	{
		return match ($this) {
			self::Open => 'Otevřené',
			self::Mine => 'Moje',
			self::Overdue => 'Po termínu',
			self::All => 'Všechny',
		};
	}
}
