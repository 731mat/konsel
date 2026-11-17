<?php

declare(strict_types=1);

namespace App\Model\Municipality;

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\HexColor;

/**
 * Barvy obce v aplikaci: hlavní (tlačítka, odkazy, zvýraznění) a doplňková (proužek v liště, akcenty).
 */
final readonly class BrandColors
{
	public const string DefaultPrimary = '#1b5e8c';

	public const string DefaultAccent = '#e3b45d';

	/**
	 * Minimální kontrast hlavní barvy vůči bílé – jinak by odkazy a tlačítka nebyly čitelné.
	 */
	public const float MinPrimaryContrast = 3.0;

	/**
	 * @throws ValidationException
	 */
	public function __construct(
		public HexColor $primary,
		public HexColor $accent,
	) {
		if ($primary->contrastWith(HexColor::white()) < self::MinPrimaryContrast) {
			throw new ValidationException('Hlavní barva je příliš světlá, odkazy a tlačítka by nebyly čitelné. Zvolte tmavší odstín.', 'primaryColor');
		}
	}

	/**
	 * @throws ValidationException
	 */
	public static function fromStrings(string $primary, string $accent): self
	{
		return new self(new HexColor($primary, 'primaryColor'), new HexColor($accent, 'accentColor'));
	}

	public static function default(): self
	{
		return new self(new HexColor(self::DefaultPrimary), new HexColor(self::DefaultAccent));
	}

	public function isDefault(): bool
	{
		return $this->equals(self::default());
	}

	public function equals(self $other): bool
	{
		return $this->primary->equals($other->primary) && $this->accent->equals($other->accent);
	}
}
