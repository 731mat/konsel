<?php

declare(strict_types=1);

namespace App\Core\Doctrine\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Types;

/**
 * Definice sloupců pro migrace (TableBuilder i alterTable()).
 */
final class ColumnFactory
{
	/**
	 * @param non-empty-string $name
	 */
	public static function make(
		string $name,
		string $type,
		?int $length = null,
		bool $nullable = false,
		mixed $default = null,
		bool $autoincrement = false,
	): Column {
		$editor = Column::editor()
			->setUnquotedName($name)
			->setTypeName($type)
			->setNotNull(!$nullable)
			->setDefaultValue($default)
			->setAutoincrement($autoincrement);

		if ($length !== null) {
			$editor->setLength($length);
		}

		return $editor->create();
	}

	/**
	 * @param non-empty-string $name
	 */
	public static function string(string $name, int $length = 255, bool $nullable = false, ?string $default = null): Column
	{
		return self::make($name, Types::STRING, length: $length, nullable: $nullable, default: $default);
	}

	/**
	 * @param non-empty-string $name
	 */
	public static function integer(string $name, bool $nullable = false, ?int $default = null): Column
	{
		return self::make($name, Types::INTEGER, nullable: $nullable, default: $default);
	}

	/**
	 * @param non-empty-string $name
	 */
	public static function text(string $name, bool $nullable = false): Column
	{
		return self::make($name, Types::TEXT, nullable: $nullable);
	}

	/**
	 * @param non-empty-string $name
	 */
	public static function boolean(string $name, bool $nullable = false, ?bool $default = null): Column
	{
		return self::make($name, Types::BOOLEAN, nullable: $nullable, default: $default);
	}

	/**
	 * @param non-empty-string $name
	 */
	public static function datetime(string $name, bool $nullable = false): Column
	{
		return self::make($name, Types::DATETIME_IMMUTABLE, nullable: $nullable);
	}

	/**
	 * @param non-empty-string $name
	 */
	public static function date(string $name, bool $nullable = false): Column
	{
		return self::make($name, Types::DATE_IMMUTABLE, nullable: $nullable);
	}

	/**
	 * JSON sloupec (i pro vlastní typy postavené na JSON, např. stored_file).
	 *
	 * @param non-empty-string $name
	 */
	public static function json(string $name, bool $nullable = false): Column
	{
		return self::make($name, Types::JSON, nullable: $nullable);
	}
}
