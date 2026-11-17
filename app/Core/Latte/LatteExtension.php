<?php

declare(strict_types=1);

namespace App\Core\Latte;

use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Core\Tenancy\CurrentMunicipality;
use App\Model\Meeting\AttendanceEntry;
use App\Model\Meeting\Vote;
use App\Model\Municipality\Module;
use App\Model\People\Membership;
use App\Model\People\Person;
use App\Model\Shared\RichText\HtmlSanitizer;
use App\Model\Shared\RichText\RichText;
use App\Model\Shared\ValueObject\StoredFile;
use App\UI\Theme\BrandStyle;
use Latte\Extension;
use Latte\Runtime\Html as LatteHtml;
use Nette\Security\User;
use Nette\Utils\Html;

/**
 * Filtry a funkce Latte pro celou aplikaci.
 *
 * Filtry:  |czDate  |czDateLong  |czDateTime  |czMonth  |nl2p  |richText  |fileIcon  |initials  |genitiveBody
 *          |names (seznam jmen)  |period (tečka na konec věty)  |where: fn($x) => … (výběr z pole, jen funkce)
 * Funkce:  vite('assets/main.js'), allowed(Resource::Meeting, Privilege::Edit), brandStyle(), moduleEnabled(Module::Plans)
 */
final class LatteExtension extends Extension
{
	public function __construct(
		private readonly ViteAssets $viteAssets,
		private readonly User $user,
		private readonly HtmlSanitizer $sanitizer,
		private readonly CurrentMunicipality $currentMunicipality,
	) {}

	/**
	 * @return array<string, \Closure>
	 */
	public function getFilters(): array // @phpstan-ignore missingType.callable (signatury filtrů se liší, Latte API vrací array<string, callable>)
	{
		return [
			'czDate' => self::czDate(...),
			'czDateLong' => self::czDateLong(...),
			'czDateTime' => self::czDateTime(...),
			'genitiveBody' => self::genitiveBody(...),
			'czMonth' => self::czMonth(...),
			'fileIcon' => self::fileIcon(...),
			'initials' => self::initials(...),
			'nl2p' => self::nl2p(...),
			'richText' => $this->richText(...),
			'names' => self::names(...),
			'period' => self::period(...),
			'where' => self::where(...),
		];
	}

	/**
	 * @return array<string, \Closure>
	 */
	public function getFunctions(): array // @phpstan-ignore missingType.callable
	{
		return [
			'vite' => fn(string $entry): Html => $this->viteAssets->tags($entry),
			'allowed' => fn(Resource $resource, Privilege $privilege = Privilege::View): bool => $this->user->isAllowed($resource->value, $privilege->value),
			'brandStyle' => $this->brandStyle(...),
			'moduleEnabled' => $this->moduleEnabled(...),
		];
	}

	/**
	 * <style> s barvami obce aktuálního požadavku (prázdné, pokud obec používá výchozí barvy).
	 */
	public function brandStyle(): LatteHtml
	{
		if (!$this->currentMunicipality->isSet() || !$this->currentMunicipality->get()->hasCustomBrandColors()) {
			return new LatteHtml('');
		}

		return new LatteHtml('<style>' . BrandStyle::css($this->currentMunicipality->get()->getBrandColors()) . '</style>');
	}

	/**
	 * Volitelný modul obce aktuálního požadavku (bez obce = zapnuto).
	 */
	public function moduleEnabled(Module $module): bool
	{
		return !$this->currentMunicipality->isSet() || $this->currentMunicipality->get()->isModuleEnabled($module);
	}

	/**
	 * „Ing. Jan Dvořák, Marie Svobodová“ ze seznamu osob, řádků docházky, hlasů nebo členství.
	 *
	 * @param iterable<mixed> $items
	 */
	public static function names(iterable $items, string $separator = ', '): string
	{
		$names = [];
		foreach ($items as $item) {
			$person = match (true) {
				$item instanceof Person => $item,
				$item instanceof AttendanceEntry => $item->person,
				$item instanceof Vote, $item instanceof Membership => $item->getPerson(),
				default => null,
			};
			if ($person !== null) {
				$names[] = $person->getFullName();
			}
		}

		return implode($separator, $names);
	}

	/**
	 * Tečka na konec věty – jen pokud tam už není (např. „Ph.D.“).
	 */
	public static function period(?string $text): string
	{
		$text = rtrim((string) $text);

		return $text === '' || substr($text, -1) === '.' ? $text : $text . '.';
	}

	/**
	 * Výběr prvků pole podmínkou. Přijímá jen funkci (ne název funkce) – bezpečné i v sandboxu šablon dokumentů.
	 *
	 * @param iterable<mixed> $items
	 * @param \Closure(mixed): mixed $condition
	 * @return list<mixed>
	 */
	public static function where(iterable $items, \Closure $condition): array
	{
		$selected = [];
		foreach ($items as $item) {
			if ($condition($item) === true) {
				$selected[] = $item;
			}
		}

		return $selected;
	}

	public static function czDate(?\DateTimeInterface $date): string
	{
		return $date?->format('j. n. Y') ?? '';
	}

	public static function czDateTime(?\DateTimeInterface $date): string
	{
		return $date?->format('j. n. Y H:i') ?? '';
	}

	/**
	 * „pondělí 7. prosince 2026“
	 */
	public static function czDateLong(?\DateTimeInterface $date): string
	{
		if ($date === null) {
			return '';
		}

		$days = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
		$months = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

		return $days[(int) $date->format('w')] . ' ' . $date->format('j') . '. ' . $months[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
	}

	/**
	 * Druhý pád názvu orgánu: „Zastupitelstvo obce X“ → „Zastupitelstva obce X“, „Rada obce“ → „Rady obce“.
	 */
	public static function genitiveBody(string $name): string
	{
		return (string) preg_replace(
			['~^Zastupitelstvo\b~u', '~^Rada\b~u', '~^Finanční výbor\b~u', '~^Kontrolní výbor\b~u', '~^Výbor\b~u'],
			['Zastupitelstva', 'Rady', 'Finančního výboru', 'Kontrolního výboru', 'Výboru'],
			$name,
		);
	}

	/**
	 * Zkratka měsíce pro dlaždice s datem (led, úno, …).
	 */
	public static function czMonth(?\DateTimeInterface $date): string
	{
		return $date === null ? '' : ['led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'][(int) $date->format('n') - 1];
	}

	/**
	 * Iniciály pro avatar („Ing. Jan Dvořák“ → „JD“), tituly s tečkou se přeskočí.
	 */
	public static function initials(string $name): string
	{
		$parts = preg_split('~[\s,]+~u', $name);
		$words = array_values(array_filter(
			$parts === false ? [] : $parts,
			static fn(string $word): bool => $word !== '' && substr($word, -1) !== '.',
		));
		$initials = mb_substr($words[0] ?? '?', 0, 1) . (count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '');

		return mb_strtoupper($initials);
	}

	/**
	 * Třída Bootstrap ikony podle typu souboru.
	 */
	public static function fileIcon(StoredFile $file): string
	{
		return match ($file->getExtension()) {
			'pdf' => 'bi-file-earmark-pdf',
			'doc', 'docx', 'odt', 'rtf' => 'bi-file-earmark-word',
			'xls', 'xlsx', 'ods', 'csv' => 'bi-file-earmark-excel',
			'ppt', 'pptx', 'odp' => 'bi-file-earmark-ppt',
			'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff' => 'bi-file-earmark-image',
			'zip', '7z' => 'bi-file-earmark-zip',
			'mp3', 'm4a', 'wav' => 'bi-file-earmark-music',
			'mp4' => 'bi-file-earmark-play',
			default => 'bi-file-earmark',
		};
	}

	/**
	 * Formátovaný text (komentáře) jako HTML. Pro jistotu se pročistí i při výpisu.
	 */
	public function richText(?RichText $text): LatteHtml
	{
		return new LatteHtml($text === null || $text->html === '' ? '' : $this->sanitizer->sanitize($text->html)->html);
	}

	/**
	 * Prostý text s odstavci → HTML odstavce (escapované).
	 */
	public static function nl2p(?string $text): LatteHtml
	{
		$paragraphs = preg_split('~\R{2,}~', trim($text ?? ''));
		if ($paragraphs === false) {
			$paragraphs = [];
		}

		$html = '';
		foreach ($paragraphs as $paragraph) {
			if ($paragraph === '') {
				continue;
			}

			$escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$html .= '<p>' . (preg_replace('~\R~', '<br>', $escaped) ?? $escaped) . '</p>';
		}

		return new LatteHtml($html);
	}
}
