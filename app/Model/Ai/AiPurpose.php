<?php

declare(strict_types=1);

namespace App\Model\Ai;

/**
 * K čemu AI text navrhuje. Hodnota = název souboru s pokyny v resources/prompts.
 */
enum AiPurpose: string
{
	case AgendaDescription = 'agenda-description';
	case ProposedResolution = 'proposed-resolution';

	public function label(): string
	{
		return match ($this) {
			self::AgendaDescription => 'důvodová zpráva',
			self::ProposedResolution => 'návrh usnesení',
		};
	}
}
