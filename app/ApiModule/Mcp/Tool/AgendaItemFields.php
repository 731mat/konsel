<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

/**
 * Společná pole bodu programu pro nástroje create_agenda_item a update_agenda_item.
 */
final class AgendaItemFields
{
	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties(): array
	{
		return [
			'title' => [
				'type' => 'string',
				'description' => 'Název bodu, např. „Rozpočtové opatření č. 5/2026“.',
			],
			'description' => [
				'type' => 'string',
				'description' => 'Důvodová zpráva – podklad pro zastupitele (prostý text, odstavce oddělte prázdným řádkem).',
			],
			'proposed_resolution' => [
				'type' => 'string',
				'description' => 'Návrh usnesení.',
			],
			'rapporteur_id' => [
				'type' => 'integer',
				'description' => 'ID osoby předkladatele z list_people.',
			],
			'project_id' => [
				'type' => 'integer',
				'description' => 'Propojení s plánem obce (ID z list_projects); 0 = zrušit propojení.',
			],
		];
	}
}
