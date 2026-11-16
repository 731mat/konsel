<?php

declare(strict_types=1);

namespace App\Model\Template;

/**
 * Výchozí knihovna běžných usnesení zastupitelstva. Místa k doplnění jsou vyznačena tečkami.
 */
final class DefaultResolutionTemplates
{
	/**
	 * @return list<array{string, string, string}> [kategorie, název, text]
	 */
	public static function all(): array
	{
		$blank = '…………';

		return [
			['Jednání', 'Schválení programu', '{organ} schvaluje program {cislo_zasedani}. zasedání konaného dne {datum_zasedani}.'],
			['Jednání', 'Určení ověřovatelů a zapisovatele', "{organ} určuje ověřovateli zápisu {$blank} a {$blank} a zapisovatelem {$blank}."],
			['Rozpočet', 'Rozpočet obce', "{organ} schvaluje rozpočet obce {obec} na rok {$blank} jako {$blank} (vyrovnaný / přebytkový / schodkový) s celkovými příjmy {$blank} Kč a celkovými výdaji {$blank} Kč. Závazným ukazatelem jsou {$blank}."],
			['Rozpočet', 'Rozpočtové opatření', "{organ} schvaluje rozpočtové opatření č. {$blank}/{rok} dle přílohy."],
			['Rozpočet', 'Pověření starosty k rozpočtovým opatřením', "{organ} pověřuje starostu obce prováděním rozpočtových opatření do výše {$blank} Kč v jednotlivém případě."],
			['Rozpočet', 'Střednědobý výhled rozpočtu', "{organ} schvaluje střednědobý výhled rozpočtu obce {obec} na roky {$blank}–{$blank}."],
			['Rozpočet', 'Závěrečný účet', "{organ} schvaluje závěrečný účet obce {obec} za rok {$blank} spolu se zprávou o výsledku přezkoumání hospodaření, a to bez výhrad."],
			['Rozpočet', 'Účetní závěrka', "{organ} schvaluje účetní závěrku obce {obec} sestavenou k 31. 12. {$blank}."],
			['Majetek', 'Prodej pozemku', "{organ} schvaluje prodej pozemku p. č. {$blank} o výměře {$blank} m² v k. ú. {$blank} kupujícímu {$blank} za cenu {$blank} Kč a pověřuje starostu podpisem kupní smlouvy."],
			['Majetek', 'Koupě pozemku', "{organ} schvaluje koupi pozemku p. č. {$blank} v k. ú. {$blank} od {$blank} za cenu {$blank} Kč a pověřuje starostu podpisem kupní smlouvy."],
			['Majetek', 'Přijetí daru', "{organ} schvaluje přijetí daru {$blank} od {$blank} a uzavření darovací smlouvy."],
			['Majetek', 'Věcné břemeno', "{organ} schvaluje uzavření smlouvy o zřízení věcného břemene {$blank} na pozemku p. č. {$blank} ve prospěch {$blank} za jednorázovou úplatu {$blank} Kč."],
			['Majetek', 'Plán inventarizace', "{organ} schvaluje plán inventarizace majetku obce za rok {rok} a jmenuje inventarizační komisi ve složení {$blank}."],
			['Smlouvy a dotace', 'Smlouva o dílo', "{organ} schvaluje uzavření smlouvy o dílo na akci „{$blank}“ se zhotovitelem {$blank}, IČO {$blank}, za cenu {$blank} Kč bez DPH a pověřuje starostu jejím podpisem."],
			['Smlouvy a dotace', 'Přijetí dotace', "{organ} schvaluje přijetí dotace z {$blank} ve výši {$blank} Kč na akci „{$blank}“ a uzavření smlouvy o poskytnutí dotace."],
			['Smlouvy a dotace', 'Dotace z rozpočtu obce', "{organ} schvaluje poskytnutí dotace z rozpočtu obce ve výši {$blank} Kč příjemci {$blank} na {$blank} a uzavření veřejnoprávní smlouvy o poskytnutí dotace."],
			['Vyhlášky', 'Obecně závazná vyhláška', "{organ} vydává obecně závaznou vyhlášku obce {obec} č. {$blank}/{rok}, {$blank}."],
			['Ostatní', 'Bere na vědomí zprávu', "{organ} bere na vědomí {$blank}."],
			['Ostatní', 'Zápis z jednání výboru', "{organ} bere na vědomí zápis z jednání {$blank} výboru ze dne {$blank}."],
		];
	}
}
