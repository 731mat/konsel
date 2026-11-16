<?php

declare(strict_types=1);

namespace App\Model\People;

use App\Model\Shared\Exception\ValidationException;

final readonly class PersonName
{
	public string $firstName;

	public string $lastName;

	public ?string $titleBefore;

	public ?string $titleAfter;

	public function __construct(string $firstName, string $lastName, ?string $titleBefore = null, ?string $titleAfter = null)
	{
		$this->firstName = self::required($firstName, 'firstName', 'Jméno');
		$this->lastName = self::required($lastName, 'lastName', 'Příjmení');
		$this->titleBefore = self::optional($titleBefore, 'titleBefore');
		$this->titleAfter = self::optional($titleAfter, 'titleAfter');
	}

	/**
	 * "Ing. Jan Novák, Ph.D."
	 */
	public function full(): string
	{
		$name = trim(($this->titleBefore ?? '') . ' ' . $this->firstName . ' ' . $this->lastName);

		return $this->titleAfter === null ? $name : "{$name}, {$this->titleAfter}";
	}

	/**
	 * "Novák Jan" – pro řazení a prezenční listiny.
	 */
	public function sortable(): string
	{
		return "{$this->lastName} {$this->firstName}";
	}

	private static function required(string $value, string $field, string $label): string
	{
		$value = trim($value);
		if ($value === '' || mb_strlen($value) > 100) {
			throw new ValidationException("{$label} musí mít 1 až 100 znaků.", $field);
		}

		return $value;
	}

	private static function optional(?string $value, string $field): ?string
	{
		$value = trim($value ?? '');
		if (mb_strlen($value) > 50) {
			throw new ValidationException('Titul může mít nejvýše 50 znaků.', $field);
		}

		return $value === '' ? null : $value;
	}
}
