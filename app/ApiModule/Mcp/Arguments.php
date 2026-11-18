<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

/**
 * Argumenty volání nástroje s kontrolou typů (AI občas pošle číslo jako řetězec – to se toleruje).
 */
final readonly class Arguments
{
	/**
	 * @param array<mixed> $values
	 */
	public function __construct(
		private array $values,
	) {}

	public function has(string $name): bool
	{
		return array_key_exists($name, $this->values) && $this->values[$name] !== null;
	}

	/**
	 * @throws ToolException
	 */
	public function int(string $name): int
	{
		return $this->optionalInt($name) ?? throw new ToolException("Chybí povinný parametr {$name}.");
	}

	/**
	 * @throws ToolException
	 */
	public function optionalInt(string $name, ?int $default = null): ?int
	{
		$value = $this->values[$name] ?? null;
		if ($value === null || $value === '') {
			return $default;
		}

		if (is_int($value)) {
			return $value;
		}

		if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
			return (int) $value;
		}

		throw new ToolException("Parametr {$name} musí být celé číslo.");
	}

	/**
	 * Celé číslo v rozsahu (např. limit výpisu); mimo rozsah se ořízne.
	 *
	 * @throws ToolException
	 */
	public function limit(string $name, int $default, int $max): int
	{
		return max(1, min($max, $this->optionalInt($name, $default) ?? $default));
	}

	/**
	 * Povinný neprázdný text.
	 *
	 * @throws ToolException
	 */
	public function string(string $name): string
	{
		$value = $this->optionalString($name);
		if ($value === null || trim($value) === '') {
			throw new ToolException("Chybí povinný parametr {$name}.");
		}

		return $value;
	}

	/**
	 * @throws ToolException
	 */
	public function optionalString(string $name): ?string
	{
		$value = $this->values[$name] ?? null;
		if ($value === null) {
			return null;
		}

		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			throw new ToolException("Parametr {$name} musí být text.");
		}

		$value = trim((string) $value);

		return $value === '' ? null : $value;
	}

	/**
	 * @throws ToolException
	 */
	public function bool(string $name, bool $default = false): bool
	{
		$value = $this->values[$name] ?? null;

		return match (true) {
			$value === null => $default,
			is_bool($value) => $value,
			in_array($value, ['true', '1', 1], true) => true,
			in_array($value, ['false', '0', 0], true) => false,
			default => throw new ToolException("Parametr {$name} musí být true nebo false."),
		};
	}

	/**
	 * @template T of \BackedEnum
	 * @param class-string<T> $enum
	 * @return T|null
	 * @throws ToolException
	 */
	public function enum(string $name, string $enum): ?\BackedEnum
	{
		$value = $this->optionalString($name);
		if ($value === null) {
			return null;
		}

		$case = $enum::tryFrom($value);
		if ($case === null) {
			$allowed = implode(', ', array_map(static fn(\BackedEnum $case): string => (string) $case->value, $enum::cases()));

			throw new ToolException("Neplatná hodnota parametru {$name}: „{$value}“. Povolené: {$allowed}.");
		}

		return $case;
	}

	/**
	 * Datum (RRRR-MM-DD) nebo datum a čas (RRRR-MM-DD HH:MM, i ISO 8601) v čase obce.
	 *
	 * @throws ToolException
	 */
	public function dateTime(string $name, \DateTimeZone $timezone): ?\DateTimeImmutable
	{
		$value = $this->optionalString($name);
		if ($value === null) {
			return null;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?([+-]\d{2}:?\d{2}|Z)?$/', $value) !== 1) {
			throw new ToolException("Parametr {$name} musí být datum RRRR-MM-DD nebo RRRR-MM-DD HH:MM.");
		}

		try {
			return (new \DateTimeImmutable($value, $timezone))->setTimezone($timezone);
		} catch (\Exception) {
			throw new ToolException("Neplatné datum v parametru {$name}: {$value}.");
		}
	}
}
