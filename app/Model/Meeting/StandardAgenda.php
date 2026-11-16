<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\People\BodyType;

/**
 * Standardní úvodní a závěrečné body programu. Plnohodnotné šablony zasedání přinese T15.
 */
final class StandardAgenda
{
	/**
	 * @return list<AgendaItemContent>
	 */
	public static function opening(Meeting $meeting): array
	{
		$body = $meeting->getBody();
		$noun = $meeting->getKindNoun();

		if ($body->getType() !== BodyType::Council) {
			return [
				new AgendaItemContent('Zahájení', "Předsedající zahájí {$noun} a ověří usnášeníschopnost."),
				new AgendaItemContent('Schválení programu', null, "{$body->getName()} schvaluje program {$noun}."),
			];
		}

		$verifiers = $meeting->getVerifiers();
		$verifierNames = count($verifiers) === 2
			? "{$verifiers[0]->getFullName()} a {$verifiers[1]->getFullName()}"
			: '………………… a …………………';
		$recorder = $meeting->getRecorder()?->getFullName() ?? '…………………';

		return [
			new AgendaItemContent(
				'Zahájení',
				'Předsedající zahájí zasedání, konstatuje, že informace o jeho konání byla zveřejněna na úřední desce '
				. 'v souladu s § 93 odst. 1 zákona o obcích, a ověří, že je zastupitelstvo usnášeníschopné.',
			),
			new AgendaItemContent(
				'Určení ověřovatelů zápisu a zapisovatele',
				null,
				"{$body->getName()} určuje ověřovateli zápisu {$verifierNames} a zapisovatelem {$recorder}.",
			),
			new AgendaItemContent('Schválení programu', null, "{$body->getName()} schvaluje program zasedání."),
		];
	}

	/**
	 * @return list<AgendaItemContent>
	 */
	public static function closing(Meeting $meeting): array
	{
		return [
			new AgendaItemContent('Diskuse'),
			new AgendaItemContent('Závěr', "Předsedající ukončí {$meeting->getKindNoun()}."),
		];
	}
}
