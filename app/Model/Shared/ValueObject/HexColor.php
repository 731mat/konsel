<?php

declare(strict_types=1);

namespace App\Model\Shared\ValueObject;

use App\Model\Shared\Exception\ValidationException;

/**
 * Barva ve tvaru #rrggbb (malými písmeny). Umí míchání a kontrast podle WCAG.
 */
final readonly class HexColor implements \Stringable
{
	public string $value;

	/**
	 * @param string $field název pole formuláře pro chybovou hlášku
	 */
	public function __construct(string $value, string $field = 'color')
	{
		$normalized = strtolower(trim($value));
		if ($normalized !== '' && $normalized[0] !== '#') {
			$normalized = '#' . $normalized;
		}

		if (preg_match('~^#[0-9a-f]{3}$~', $normalized) === 1) {
			$normalized = '#' . $normalized[1] . $normalized[1] . $normalized[2] . $normalized[2] . $normalized[3] . $normalized[3];
		}

		if (preg_match('~^#[0-9a-f]{6}$~', $normalized) !== 1) {
			throw new ValidationException("Barva musí být ve tvaru #RRGGBB, např. #1b5e8c (zadáno „{$value}“).", $field);
		}

		$this->value = $normalized;
	}

	public static function fromRgb(int $red, int $green, int $blue): self
	{
		$clamp = static fn(int $channel): int => max(0, min(255, $channel));

		return new self(sprintf('#%02x%02x%02x', $clamp($red), $clamp($green), $clamp($blue)));
	}

	public static function white(): self
	{
		return new self('#ffffff');
	}

	public static function black(): self
	{
		return new self('#000000');
	}

	/**
	 * @return array{int, int, int}
	 */
	public function rgb(): array
	{
		return [(int) hexdec(substr($this->value, 1, 2)), (int) hexdec(substr($this->value, 3, 2)), (int) hexdec(substr($this->value, 5, 2))];
	}

	/**
	 * Smíchá barvu s jinou; $weight je podíl té druhé (0 = beze změny, 1 = jen druhá barva).
	 */
	public function mix(self $other, float $weight): self
	{
		$weight = max(0.0, min(1.0, $weight));
		[$r1, $g1, $b1] = $this->rgb();
		[$r2, $g2, $b2] = $other->rgb();
		$blend = static fn(int $a, int $b): int => (int) round($a + ($b - $a) * $weight);

		return self::fromRgb($blend($r1, $r2), $blend($g1, $g2), $blend($b1, $b2));
	}

	/**
	 * Zesvětlení (Bootstrap tint-color).
	 */
	public function tint(float $weight): self
	{
		return $this->mix(self::white(), $weight);
	}

	/**
	 * Ztmavení (Bootstrap shade-color).
	 */
	public function shade(float $weight): self
	{
		return $this->mix(self::black(), $weight);
	}

	/**
	 * Relativní jas podle WCAG 2.
	 */
	public function luminance(): float
	{
		$linear = static function (int $channel): float {
			$value = $channel / 255;

			return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
		};
		[$r, $g, $b] = $this->rgb();

		return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
	}

	/**
	 * Kontrastní poměr 1–21 podle WCAG 2.
	 */
	public function contrastWith(self $other): float
	{
		$a = $this->luminance();
		$b = $other->luminance();

		return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
	}

	public function equals(self $other): bool
	{
		return $this->value === $other->value;
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
