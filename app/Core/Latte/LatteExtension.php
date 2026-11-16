<?php

declare(strict_types=1);

namespace App\Core\Latte;

use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use Latte\Extension;
use Latte\Runtime\Html as LatteHtml;
use Nette\Security\User;
use Nette\Utils\Html;

/**
 * Filtry a funkce Latte pro celou aplikaci.
 *
 * Filtry:  |czDate  |czDateTime  |czMonth  |nl2p
 * Funkce:  vite('assets/main.js'), allowed(Resource::Meeting, Privilege::Edit)
 */
final class LatteExtension extends Extension
{
	public function __construct(
		private readonly ViteAssets $viteAssets,
		private readonly User $user,
	) {}

	/**
	 * @return array<string, \Closure>
	 */
	public function getFilters(): array // @phpstan-ignore missingType.callable (signatury filtrů se liší, Latte API vrací array<string, callable>)
	{
		return [
			'czDate' => self::czDate(...),
			'czDateTime' => self::czDateTime(...),
			'czMonth' => self::czMonth(...),
			'nl2p' => self::nl2p(...),
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
		];
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
	 * Zkratka měsíce pro dlaždice s datem (led, úno, …).
	 */
	public static function czMonth(?\DateTimeInterface $date): string
	{
		return $date === null ? '' : ['led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'][(int) $date->format('n') - 1];
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
