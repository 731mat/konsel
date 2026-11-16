<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\People\Person;
use App\Model\Shared\Exception\ValidationException;

/**
 * Obsah bodu programu (prostý text s odstavci – ADR A10). Prázdné texty se ukládají jako null.
 */
final readonly class AgendaItemContent
{
	public const int MaxTextLength = 50_000;

	public string $title;

	public ?string $description;

	public ?string $proposedResolution;

	/**
	 * @throws ValidationException
	 */
	public function __construct(
		string $title,
		?string $description = null,
		?string $proposedResolution = null,
		public ?Person $rapporteur = null,
	) {
		$title = trim((string) preg_replace('~\s+~u', ' ', $title));
		if ($title === '' || mb_strlen($title) > 300) {
			throw new ValidationException('Název bodu musí mít 1 až 300 znaků.', 'title');
		}

		$this->title = $title;
		$this->description = self::text($description, 'description');
		$this->proposedResolution = self::text($proposedResolution, 'proposedResolution');
	}

	private static function text(?string $text, string $field): ?string
	{
		$text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
		if (mb_strlen($text) > self::MaxTextLength) {
			throw new ValidationException('Text je příliš dlouhý (nejvýše ' . self::MaxTextLength . ' znaků).', $field);
		}

		return $text === '' ? null : $text;
	}
}
