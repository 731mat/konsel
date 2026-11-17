<?php

declare(strict_types=1);

namespace App\Model\Resolution;

/**
 * Rozdělení textu usnesení na úvodní sloveso a předmět – „Schvaluje: rozpočtové opatření č. 9/2026“
 * → [„Schvaluje“, „rozpočtové opatření č. 9/2026“]. Pro dokumenty ve stylu „19/2/1 – Schvaluje – program jednání“.
 */
final class ResolutionText
{
	/**
	 * Slovesa usnesení (velká písmena na začátku, malá i velká se porovnávají stejně).
	 */
	public const array Verbs = [
		'bere na vědomí', 'nebere na vědomí', 'schvaluje', 'neschvaluje', 'pověřuje', 'stanoví', 'ukládá', 'volí', 'zvolilo', 'zřizuje',
		'ruší', 'revokuje', 'souhlasí', 'nesouhlasí', 'vydává', 'jmenuje', 'odvolává', 'deleguje', 'doporučuje', 'zamítá',
		'rozhodlo', 'rozhoduje', 'určuje', 'vyhrazuje', 'svěřuje', 'konstatuje', 'projednalo', 'zmocňuje', 'vyhlašuje', 'přijímá',
	];

	/**
	 * @return array{?string, string} [sloveso nebo null, zbytek textu]
	 */
	public static function split(string $text): array
	{
		$trimmed = trim($text);
		foreach (self::Verbs as $verb) {
			if (mb_strtolower(mb_substr($trimmed, 0, mb_strlen($verb))) !== $verb) {
				continue;
			}

			$rest = mb_substr($trimmed, mb_strlen($verb));
			// za slovesem oddělovač „:“ nebo pomlčka (nebo jen mezera)
			if (preg_match('~^\s*[:–—-]\s*(.+)$~us', $rest, $match) === 1 || preg_match('~^\s+(.+)$~us', $rest, $match) === 1) {
				return [mb_strtoupper(mb_substr($trimmed, 0, 1)) . mb_substr($trimmed, 1, mb_strlen($verb) - 1), trim($match[1])];
			}
		}

		return [null, $trimmed];
	}
}
