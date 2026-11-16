<?php

declare(strict_types=1);

namespace App\Core\Doctrine\Type;

use App\Model\Shared\ValueObject\StoredFile;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use Nette\Utils\Json;
use Nette\Utils\JsonException;

/**
 * StoredFile ⇄ JSON v jednom (i nullable) sloupci. SQL deklarace je stejná jako u typu json.
 */
final class StoredFileType extends JsonType
{
	public const string Name = 'stored_file';

	public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
	{
		if ($value === null) {
			return null;
		}

		if (!$value instanceof StoredFile) {
			throw new \InvalidArgumentException('Expected ' . StoredFile::class . ', got ' . get_debug_type($value));
		}

		return Json::encode($value->toArray());
	}

	public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StoredFile
	{
		if ($value === null || $value === '') {
			return null;
		}

		if (!is_string($value)) {
			throw new \InvalidArgumentException('Expected JSON string, got ' . get_debug_type($value));
		}

		try {
			$data = Json::decode($value, forceArrays: true);
		} catch (JsonException $e) {
			throw new ConversionException('Invalid stored_file JSON: ' . $e->getMessage(), 0, $e);
		}

		return is_array($data) ? StoredFile::fromArray($data) : null;
	}
}
