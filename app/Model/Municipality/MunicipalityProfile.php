<?php

declare(strict_types=1);

namespace App\Model\Municipality;

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\Email;

/**
 * Údaje obce pro hlavičky dokumentů a kontakty. Validuje a normalizuje vstup.
 */
final readonly class MunicipalityProfile
{
	public string $officialName;

	public ?string $street;

	public ?string $city;

	public ?string $postalCode;

	public ?Email $email;

	public ?string $phone;

	public ?string $website;

	public ?int $population;

	public function __construct(
		string $officialName,
		?string $street = null,
		?string $city = null,
		?string $postalCode = null,
		?Email $email = null,
		?string $phone = null,
		?string $website = null,
		?int $population = null,
	) {
		$this->officialName = self::requireText($officialName, 200, 'officialName', 'Oficiální název');
		$this->street = self::optionalText($street, 200, 'street', 'Ulice a číslo');
		$this->city = self::optionalText($city, 100, 'city', 'Obec / město');
		$this->postalCode = self::normalizePostalCode($postalCode);
		$this->email = $email;
		$this->phone = self::optionalText($phone, 50, 'phone', 'Telefon');
		$this->website = self::normalizeWebsite($website);

		if ($population !== null && ($population < 0 || $population > 10_000_000)) {
			throw new ValidationException('Počet obyvatel musí být kladné číslo.', 'population');
		}

		$this->population = $population;
	}

	/**
	 * Adresa na jeden řádek pro hlavičky dokumentů, např. "Neplachovice 1, 747 74 Neplachovice".
	 */
	public function getAddressLine(): string
	{
		$cityPart = trim(($this->postalCode ?? '') . ' ' . ($this->city ?? ''));

		return implode(', ', array_filter([$this->street, $cityPart], static fn(?string $part): bool => $part !== null && $part !== ''));
	}

	private static function requireText(string $value, int $maxLength, string $field, string $label): string
	{
		$value = trim($value);
		if ($value === '' || mb_strlen($value) > $maxLength) {
			throw new ValidationException("{$label} musí mít 1 až {$maxLength} znaků.", $field);
		}

		return $value;
	}

	private static function optionalText(?string $value, int $maxLength, string $field, string $label): ?string
	{
		$value = $value === null ? null : trim($value);
		if ($value === null || $value === '') {
			return null;
		}

		if (mb_strlen($value) > $maxLength) {
			throw new ValidationException("{$label} může mít nejvýše {$maxLength} znaků.", $field);
		}

		return $value;
	}

	private static function normalizePostalCode(?string $value): ?string
	{
		$digits = preg_replace('~\s+~', '', $value ?? '') ?? '';
		if ($digits === '') {
			return null;
		}

		if (preg_match('~^\d{5}$~', $digits) !== 1) {
			throw new ValidationException('PSČ musí mít 5 číslic.', 'postalCode');
		}

		return substr($digits, 0, 3) . ' ' . substr($digits, 3);
	}

	private static function normalizeWebsite(?string $value): ?string
	{
		$value = trim($value ?? '');
		if ($value === '') {
			return null;
		}

		if (preg_match('~^https?://~i', $value) !== 1) {
			$value = 'https://' . $value;
		}

		if (mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_URL) === false) {
			throw new ValidationException('Neplatná adresa webu.', 'website');
		}

		return $value;
	}
}
