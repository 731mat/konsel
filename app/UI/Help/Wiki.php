<?php

declare(strict_types=1);

namespace App\UI\Help;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Wiki projektu (doc/wiki/*.md) zobrazená v aplikaci v menu Nápověda.
 *
 * Markdown převádí league/commonmark (GFM: tabulky, odkazy). HTML ve zdroji se escapuje,
 * bloky ```mermaid vykreslí v prohlížeči knihovna Mermaid (assets/main.js).
 */
final class Wiki
{
	private ?GithubFlavoredMarkdownConverter $converter = null;

	public function __construct(
		private readonly string $directory,
	) {}

	/**
	 * @return list<WikiPage> podle čísla v názvu souboru
	 */
	public function pages(): array
	{
		$files = glob($this->directory . '/*.md');
		$files = $files === false ? [] : $files;
		sort($files);

		return array_map(fn(string $path): WikiPage => new WikiPage(basename($path, '.md'), $this->title($path), $path), $files);
	}

	public function find(string $slug): ?WikiPage
	{
		foreach ($this->pages() as $page) {
			if ($page->slug === $slug) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * HTML stránky; odkazy mezi stránkami wiki (01-xxx.md) převede na odkazy v aplikaci.
	 *
	 * @param \Closure(string): string $pageLink slug → URL stránky v aplikaci
	 */
	public function render(WikiPage $page, \Closure $pageLink): string
	{
		$this->converter ??= new GithubFlavoredMarkdownConverter([
			'html_input' => 'escape',
			'allow_unsafe_links' => false,
		]);
		$markdown = (string) file_get_contents($page->path);
		// první nadpis je titulek stránky – vykresluje ho šablona
		$markdown = (string) preg_replace('~\A\s*# [^\n]*\n~u', '', $markdown);
		$html = $this->converter->convert($markdown)->getContent();

		return (string) preg_replace_callback(
			'~href="(\d{2}-[a-z0-9-]+)\.md(#[^"]*)?"~',
			static fn(array $m): string => 'href="' . htmlspecialchars($pageLink($m[1]) . ($m[2] ?? ''), ENT_QUOTES) . '"',
			$html,
		);
	}

	private function title(string $path): string
	{
		$handle = fopen($path, 'r');
		$line = $handle === false ? false : fgets($handle);
		if ($handle !== false) {
			fclose($handle);
		}

		return is_string($line) && strncmp($line, '# ', 2) === 0 ? trim(substr($line, 2)) : basename($path, '.md');
	}
}
