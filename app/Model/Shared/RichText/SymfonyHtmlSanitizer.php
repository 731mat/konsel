<?php

declare(strict_types=1);

namespace App\Model\Shared\RichText;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as Sanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class SymfonyHtmlSanitizer implements HtmlSanitizer
{
	/**
	 * Značky, které umí editor komentářů.
	 */
	public const array AllowedElements = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ul', 'ol', 'li', 'blockquote', 'code'];

	/**
	 * Obalové značky (typicky z Wordu nebo webu): značka zmizí, text zůstane.
	 * Všechno ostatní (script, style, iframe, svg, formuláře…) se zahodí i s obsahem.
	 */
	private const array BlockedElements = [
		'span', 'font', 'mark', 'small', 'big', 'sup', 'sub', 'abbr', 'cite', 'q', 'ins', 'kbd', 'samp', 'var', 'time', 'label', 'center',
		'address', 'table', 'thead', 'tbody', 'tfoot', 'td', 'th', 'dl', 'dt', 'dd', 'figure', 'figcaption', 'main', 'aside', 'nav',
	];

	private ?Sanitizer $sanitizer = null;

	public function sanitize(string $input): RichText
	{
		if (preg_match('~<(?:[a-z][a-z0-9]*|/[a-z]|!--)~i', $input) !== 1) {
			return RichText::fromPlainText($input);
		}

		// blokové značky mimo editor (vložené odjinud) → odstavce, aby se text neslepil
		$input = (string) preg_replace('~<(/?)(?:h[1-6]|div|pre|section|article|header|footer|tr)\b[^>]*>~i', '<$1p>', $input);
		$html = $this->sanitizer()->sanitize($input);
		// prázdné odstavce na konci (Enter před odesláním) a nezlomitelné mezery z editoru
		$html = (string) preg_replace('~(?:<p>(?:\s|&nbsp;|<br\s*/?>)*</p>)+$~u', '', trim($html));

		return RichText::fromSanitizedHtml($html);
	}

	private function sanitizer(): Sanitizer
	{
		if ($this->sanitizer !== null) {
			return $this->sanitizer;
		}

		$config = (new HtmlSanitizerConfig())
			->defaultAction(HtmlSanitizerAction::Drop)
			->allowElement('a', ['href'])
			->allowLinkSchemes(['http', 'https', 'mailto'])
			->allowRelativeLinks(false)
			->forceHttpsUrls(false)
			->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
			->forceAttribute('a', 'target', '_blank')
			->withMaxInputLength(200_000);

		foreach (self::AllowedElements as $element) {
			$config = $config->allowElement($element);
		}

		foreach (self::BlockedElements as $element) {
			$config = $config->blockElement($element);
		}

		return $this->sanitizer = new Sanitizer($config);
	}
}
