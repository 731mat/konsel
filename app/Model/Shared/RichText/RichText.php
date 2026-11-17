<?php

declare(strict_types=1);

namespace App\Model\Shared\RichText;

/**
 * Formátovaný text (komentáře): bezpečné HTML s omezenou sadou značek.
 *
 * Vzniká jen přes HtmlSanitizer (vstup od uživatele), z prostého textu, nebo z databáze,
 * kam se ukládá už pročištěný. Obsah se proto smí vypsat jako HTML.
 */
final readonly class RichText
{
	private function __construct(
		public string $html,
	) {}

	/**
	 * HTML, které už prošlo sanitizérem (např. načtené z databáze).
	 */
	public static function fromSanitizedHtml(string $html): self
	{
		return new self(trim($html));
	}

	/**
	 * Prostý text → odstavce (prázdný řádek) a zalomení řádků.
	 */
	public static function fromPlainText(string $text): self
	{
		$text = trim(str_replace(["\r\n", "\r"], "\n", $text));
		if ($text === '') {
			return new self('');
		}

		$paragraphs = preg_split('~\n{2,}~', $text);
		$html = '';
		foreach ($paragraphs === false ? [$text] : $paragraphs as $paragraph) {
			$escaped = htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
			$html .= '<p>' . str_replace("\n", '<br>', $escaped) . '</p>';
		}

		return new self($html);
	}

	public static function empty(): self
	{
		return new self('');
	}

	/**
	 * Text bez značek (pro náhledy, e-maily, délku). Bloky a zalomení se převedou na nové řádky.
	 */
	public function toPlainText(): string
	{
		$text = (string) preg_replace(['~<br\s*/?>~i', '~</(p|blockquote)>~i', '~</li>~i'], ["\n", "\n\n", "\n"], $this->html);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace("\u{A0}", ' ', $text);

		return trim((string) preg_replace("~\n{3,}~", "\n\n", $text));
	}

	public function isEmpty(): bool
	{
		return $this->toPlainText() === '';
	}

	/**
	 * Počet znaků textu (bez značek).
	 */
	public function length(): int
	{
		return mb_strlen($this->toPlainText());
	}
}
