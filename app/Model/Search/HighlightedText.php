<?php

declare(strict_types=1);

namespace App\Model\Search;

/**
 * Text se zvýrazněnými shodami jako úseky – šablona je vypíše s escapováním a shody obalí <mark>.
 */
final readonly class HighlightedText
{
	public const string Open = "\u{2}";

	public const string Close = "\u{3}";

	/**
	 * @param list<array{string, bool}> $segments [text, zvýrazněno]
	 */
	public function __construct(
		public array $segments,
	) {}

	/**
	 * Z textu, ve kterém index označil shody řídicími znaky Open/Close.
	 */
	public static function fromMarked(string $marked): self
	{
		$segments = [];
		$parts = preg_split('~(' . self::Open . '[^' . self::Close . ']*' . self::Close . ')~u', $marked, flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		foreach ($parts === false ? [] : $parts as $part) {
			$highlighted = strncmp($part, self::Open, 1) === 0;
			$text = str_replace([self::Open, self::Close], '', $part);
			if ($text !== '') {
				$segments[] = [$text, $highlighted];
			}
		}

		return new self($segments);
	}

	public function toPlainText(): string
	{
		return implode('', array_map(static fn(array $segment): string => $segment[0], $this->segments));
	}
}
