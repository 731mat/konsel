<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Meeting\MeetingType;

/**
 * Výchozí šablony programu. Ustavující zasedání vychází z metodického doporučení MV č. 6.4.
 * TODO ověřit proti metodice MV – texty musí před ostrým použitím zkontrolovat člověk (viz Deník T15).
 */
final class DefaultMeetingTemplates
{
	private const string Blank = '…………';

	private const string VerifyNote = 'Texty vycházejí z metodického doporučení Ministerstva vnitra k ustavujícímu zasedání. Před použitím je porovnejte s aktuální verzí metodiky.';

	/**
	 * @return list<array{string, MeetingType, non-empty-list<MeetingTemplateItem>, ?string}> [název, typ, body, poznámka]
	 */
	public static function all(): array
	{
		return [
			['Řádné zasedání zastupitelstva', MeetingType::Regular, self::regular(), null],
			['Ustavující zasedání – bez rady (méně než 15 zastupitelů)', MeetingType::Constituent, self::constituent(withBoard: false), self::VerifyNote],
			['Ustavující zasedání – s radou (15 a více zastupitelů)', MeetingType::Constituent, self::constituent(withBoard: true), self::VerifyNote],
		];
	}

	/**
	 * @return non-empty-list<MeetingTemplateItem>
	 */
	private static function regular(): array
	{
		return [
			new MeetingTemplateItem('Zahájení', 'Předsedající zahájí zasedání, konstatuje, že informace o jeho konání byla zveřejněna na úřední desce v souladu s § 93 odst. 1 zákona o obcích, a ověří, že je zastupitelstvo usnášeníschopné.'),
			new MeetingTemplateItem('Určení ověřovatelů zápisu a zapisovatele', null, '{organ} určuje ověřovateli zápisu ' . self::Blank . ' a ' . self::Blank . ' a zapisovatelem ' . self::Blank . '.'),
			new MeetingTemplateItem('Schválení programu', null, '{organ} schvaluje program {cislo_zasedani}. zasedání konaného dne {datum_zasedani}.'),
			new MeetingTemplateItem('Kontrola plnění usnesení', 'Informace o plnění usnesení z minulých zasedání.', '{organ} bere na vědomí informaci o plnění usnesení.'),
			new MeetingTemplateItem('Rozpočtové opatření', null, '{organ} schvaluje rozpočtové opatření č. ' . self::Blank . '/{rok} dle přílohy.'),
			new MeetingTemplateItem('Různé'),
			new MeetingTemplateItem('Diskuse'),
			new MeetingTemplateItem('Závěr', 'Předsedající ukončí zasedání.'),
		];
	}

	/**
	 * @return non-empty-list<MeetingTemplateItem>
	 */
	private static function constituent(bool $withBoard): array
	{
		$items = [
			new MeetingTemplateItem('Zahájení', 'Zasedání zahajuje dosavadní starosta (případně nejstarší člen zastupitelstva), který předsedá do zvolení nového starosty. Konstatuje, že informace o zasedání byla zveřejněna na úřední desce v souladu s § 93 odst. 1 zákona o obcích, že členům zastupitelstva byla předána osvědčení o zvolení a že je přítomna nadpoloviční většina členů.'),
			new MeetingTemplateItem('Složení slibu členů zastupitelstva', 'Předsedající vyzve členy zastupitelstva ke složení slibu podle § 69 odst. 2 zákona o obcích: „Slibuji věrnost České republice. Slibuji na svou čest, že svoji funkci budu vykonávat svědomitě, v zájmu obce a jejích občanů a řídit se Ústavou a zákony České republiky.“ Slib se skládá pronesením slova „slibuji“ a podpisem na připraveném archu. Odmítnutí slibu nebo slib s výhradou má za následek zánik mandátu.'),
			new MeetingTemplateItem('Určení ověřovatelů zápisu a zapisovatele', null, '{organ} určuje ověřovateli zápisu ' . self::Blank . ' a ' . self::Blank . ' a zapisovatelem ' . self::Blank . '.'),
			new MeetingTemplateItem('Schválení programu', null, '{organ} schvaluje program ustavujícího zasedání.'),
			new MeetingTemplateItem('Určení počtu místostarostů', null, '{organ} schvaluje zvolení ' . self::Blank . ' místostarosty.'),
			new MeetingTemplateItem('Určení funkcí, pro které budou členové zastupitelstva uvolněni', null, '{organ} v souladu s § 71 zákona o obcích určuje, že pro výkon funkce starosty obce bude člen zastupitelstva obce dlouhodobě uvolněn / neuvolněn.'),
			new MeetingTemplateItem('Určení způsobu volby starosty a místostarosty', 'Volba je zpravidla veřejná (aklamací), zastupitelstvo může rozhodnout o tajné volbě.', '{organ} schvaluje veřejný způsob volby starosty a místostarosty.'),
			new MeetingTemplateItem('Volba starosty', 'Předsedající vyzve k podávání návrhů. Hlasuje se o návrzích v pořadí, v jakém byly podány; zvolen je kandidát, který získá nadpoloviční většinu hlasů všech členů zastupitelstva.', '{organ} volí starostou obce ' . self::Blank . '.'),
			new MeetingTemplateItem('Volba místostarosty', null, '{organ} volí místostarostou obce ' . self::Blank . '.'),
		];

		if ($withBoard) {
			$items[] = new MeetingTemplateItem('Určení počtu členů rady', 'Rada obce má nejméně 5 a nejvýše 11 členů, počet musí být lichý (§ 99 zákona o obcích).', '{organ} stanoví, že rada obce bude mít ' . self::Blank . ' členů.');
			$items[] = new MeetingTemplateItem('Volba dalších členů rady', null, '{organ} volí členy rady obce ' . self::Blank . '.');
		}

		return [
			...$items,
			new MeetingTemplateItem('Zřízení finančního a kontrolního výboru', 'Zastupitelstvo zřizuje vždy finanční a kontrolní výbor (§ 117 zákona o obcích). Členem kontrolního a finančního výboru nemůže být starosta, místostarosta, člen rady ani osoby zabezpečující rozpočtové a účetní práce na obecním úřadu.', '{organ} zřizuje finanční výbor a kontrolní výbor. Oba výbory budou ' . self::Blank . 'členné.'),
			new MeetingTemplateItem('Volba předsedů a členů výborů', null, '{organ} volí předsedou finančního výboru ' . self::Blank . ', předsedou kontrolního výboru ' . self::Blank . ', členy finančního výboru ' . self::Blank . ' a členy kontrolního výboru ' . self::Blank . '.'),
			new MeetingTemplateItem('Odměny neuvolněným členům zastupitelstva', 'Výše odměn podle nařízení vlády o výši odměn členů zastupitelstev územních samosprávných celků.', '{organ} v souladu s § 72 a § 84 odst. 2 zákona o obcích stanoví odměnu za výkon funkce neuvolněného člena zastupitelstva ve výši ' . self::Blank . ' Kč měsíčně. Odměna bude poskytována ode dne přijetí tohoto usnesení.'),
			new MeetingTemplateItem('Diskuse'),
			new MeetingTemplateItem('Závěr', 'Předsedající ukončí zasedání.'),
		];
	}
}
