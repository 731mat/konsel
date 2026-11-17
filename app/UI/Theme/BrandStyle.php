<?php

declare(strict_types=1);

namespace App\UI\Theme;

use App\Model\Municipality\BrandColors;
use App\Model\Shared\ValueObject\HexColor;

/**
 * CSS proměnné s barvami obce. Přebíjí výchozí téma z assets/main.scss (sekce „Barvy obce“),
 * odvozené odstíny počítá stejně jako Bootstrap (tint/shade) pro světlý i tmavý režim.
 */
final class BrandStyle
{
	private const string DarkText = '#111827';

	public static function css(BrandColors $colors): string
	{
		$primary = $colors->primary;
		$accent = $colors->accent;
		$onPrimary = self::onColor($primary);
		$lightText = $onPrimary->equals(HexColor::white());

		$light = [
			'--bs-primary' => $primary->value,
			'--bs-primary-rgb' => self::rgb($primary),
			'--bs-primary-text-emphasis' => $primary->shade(0.6)->value,
			'--bs-primary-bg-subtle' => $primary->tint(0.8)->value,
			'--bs-primary-border-subtle' => $primary->tint(0.6)->value,
			'--bs-link-color' => $primary->value,
			'--bs-link-color-rgb' => self::rgb($primary),
			'--bs-link-hover-color' => $primary->shade(0.2)->value,
			'--bs-link-hover-color-rgb' => self::rgb($primary->shade(0.2)),
			'--bs-focus-ring-color' => 'rgba(' . self::rgb($primary) . ', .25)',
			'--konsel-on-primary' => $onPrimary->value,
			'--konsel-primary-hover' => ($lightText ? $primary->shade(0.15) : $primary->tint(0.15))->value,
			'--konsel-primary-active' => ($lightText ? $primary->shade(0.2) : $primary->tint(0.2))->value,
			'--konsel-primary-deep' => $primary->shade(0.35)->value,
			'--konsel-accent' => $accent->value,
			'--konsel-accent-rgb' => self::rgb($accent),
			'--konsel-accent-text' => self::readableOn($accent, $accent->tint(0.8), dark: false)->value,
			'--konsel-accent-bg-subtle' => $accent->tint(0.8)->value,
		];

		$dark = [
			'--bs-primary-text-emphasis' => $primary->tint(0.4)->value,
			'--bs-primary-bg-subtle' => $primary->shade(0.8)->value,
			'--bs-primary-border-subtle' => $primary->shade(0.4)->value,
			'--bs-link-color' => $primary->tint(0.4)->value,
			'--bs-link-color-rgb' => self::rgb($primary->tint(0.4)),
			'--bs-link-hover-color' => $primary->tint(0.6)->value,
			'--bs-link-hover-color-rgb' => self::rgb($primary->tint(0.6)),
			'--konsel-accent-text' => self::readableOn($accent, $accent->shade(0.75), dark: true)->value,
			'--konsel-accent-bg-subtle' => $accent->shade(0.75)->value,
		];

		return ':root,[data-bs-theme=light]{' . self::declarations($light) . '}'
			. '[data-bs-theme=dark]{' . self::declarations($dark) . '}';
	}

	/**
	 * Barva textu na ploše dané barvy: bílá, nebo tmavá – podle lepšího kontrastu.
	 */
	public static function onColor(HexColor $background): HexColor
	{
		$dark = new HexColor(self::DarkText);

		return $background->contrastWith(HexColor::white()) >= $background->contrastWith($dark) ? HexColor::white() : $dark;
	}

	/**
	 * Odstín barvy čitelný na daném pozadí (kontrast aspoň 4,5:1): postupně ztmavuje (zesvětluje v tmavém režimu).
	 */
	private static function readableOn(HexColor $color, HexColor $background, bool $dark): HexColor
	{
		$candidate = $dark ? $color->tint(0.2) : $color->shade(0.4);
		for ($step = 0; $step < 10 && $candidate->contrastWith($background) < 4.5; $step++) {
			$candidate = $dark ? $candidate->tint(0.2) : $candidate->shade(0.2);
		}

		return $candidate;
	}

	private static function rgb(HexColor $color): string
	{
		return implode(', ', $color->rgb());
	}

	/**
	 * @param array<string, string> $properties
	 */
	private static function declarations(array $properties): string
	{
		$css = '';
		foreach ($properties as $name => $value) {
			$css .= "{$name}:{$value};";
		}

		return $css;
	}
}
