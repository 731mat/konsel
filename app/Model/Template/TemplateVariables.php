<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Meeting\Meeting;

/**
 * Proměnné v šablonách usnesení a programu. Neznámé proměnné zůstanou v textu beze změny.
 */
final class TemplateVariables
{
	/**
	 * @return array<string, string> proměnná → popis (pro nápovědu v UI)
	 */
	public static function describe(): array
	{
		return [
			'{organ}' => 'název orgánu, např. Zastupitelstvo obce Neplachovice',
			'{obec}' => 'název obce',
			'{cislo_zasedani}' => 'pořadové číslo zasedání',
			'{datum_zasedani}' => 'datum zasedání (7. 12. 2026)',
			'{rok}' => 'rok zasedání',
		];
	}

	public static function apply(string $text, Meeting $meeting): string
	{
		return strtr($text, [
			'{organ}' => $meeting->getBody()->getName(),
			'{obec}' => $meeting->getMunicipality()->getName(),
			'{cislo_zasedani}' => (string) $meeting->getNumber(),
			'{datum_zasedani}' => $meeting->getScheduledAt()->format('j. n. Y'),
			'{rok}' => $meeting->getScheduledAt()->format('Y'),
		]);
	}
}
