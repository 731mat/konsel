<?php

declare(strict_types=1);

namespace App\Core\Latte;

use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Shared\ValueObject\StoredFile;
use Latte\Extension;
use Latte\Runtime\Html as LatteHtml;
use Nette\Security\User;
use Nette\Utils\Html;

/**
 * Filtry a funkce Latte pro celou aplikaci.
 *
 * Filtry:  |czDate  |czDateTime  |czMonth  |nl2p  |fileIcon  |initials
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
			'fileIcon' => self::fileIcon(...),
			'initials' => self::initials(...),
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
	 * Iniciály pro avatar („Ing. Jan Dvořák“ → „JD“), tituly s tečkou se přeskočí.
	 */
	public static function initials(string $name): string
	{
		$parts = preg_split('~[\s,]+~u', $name);
		$words = array_values(array_filter(
			$parts === false ? [] : $parts,
			static fn(string $word): bool => $word !== '' && !str_ends_with($word, '.'),
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
