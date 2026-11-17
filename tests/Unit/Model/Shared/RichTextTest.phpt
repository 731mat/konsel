<?php

declare(strict_types=1);

use App\Model\Shared\RichText\RichText;
use App\Model\Shared\RichText\SymfonyHtmlSanitizer;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('plain text becomes escaped paragraphs with line breaks', function (): void {
	$text = RichText::fromPlainText("  Dobrý den,\r\n\r\npoložka <b>A</b> & B\ndruhý řádek  ");

	Assert::same('<p>Dobrý den,</p><p>položka &lt;b&gt;A&lt;/b&gt; &amp; B<br>druhý řádek</p>', $text->html);
	Assert::same("Dobrý den,\n\npoložka <b>A</b> & B\ndruhý řádek", $text->toPlainText());
	Assert::true(RichText::fromPlainText(" \n ")->isEmpty());
});

test('plain text and length ignore markup', function (): void {
	$text = RichText::fromSanitizedHtml('<p>Ahoj&nbsp;<strong>světe</strong></p><ul><li><p>jedna</p></li><li><p>dvě</p></li></ul>');

	Assert::same("Ahoj světe\n\njedna\n\ndvě", $text->toPlainText());
	Assert::false($text->isEmpty());
	Assert::true(RichText::fromSanitizedHtml('<p>&nbsp;</p><p><br></p>')->isEmpty());
});

test('sanitizer allows formatting, safe links only and drops scripts', function (): void {
	$sanitizer = new SymfonyHtmlSanitizer();

	$html = $sanitizer->sanitize('<p style="color:red" onclick="x()">A <em>b</em> <u>c</u> <s>d</s> <code>e</code></p><blockquote><p>citace</p></blockquote>'
		. '<p><a href="mailto:starosta@obec.cz">napište</a> <a href="/admin">relativní</a> <a href="data:text/html,x">data</a></p>'
		. '<script>document.cookie</script><style>p{}</style><iframe src="https://evil"></iframe>')->html;

	Assert::same(
		'<p>A <em>b</em> <u>c</u> <s>d</s> <code>e</code></p><blockquote><p>citace</p></blockquote>'
		. '<p><a href="mailto:starosta&#64;obec.cz" rel="noopener noreferrer nofollow" target="_blank">napište</a> '
		. '<a rel="noopener noreferrer nofollow" target="_blank">relativní</a> <a rel="noopener noreferrer nofollow" target="_blank">data</a></p>',
		$html,
	);
});

test('sanitizer keeps text of unknown blocks as paragraphs and trims trailing empty paragraphs', function (): void {
	$sanitizer = new SymfonyHtmlSanitizer();

	Assert::same('<p>Nadpis</p><p>z Wordu</p>', $sanitizer->sanitize('<h1>Nadpis</h1><div><span style="font-family:Calibri">z Wordu</span></div>')->html);
	Assert::same('<p>Text</p>', $sanitizer->sanitize('<p>Text</p><p></p><p><br></p><p>&nbsp;</p>')->html);
	Assert::same('<p>a &lt; b</p>', $sanitizer->sanitize('a < b')->html, 'text bez značek je prostý text');
});
