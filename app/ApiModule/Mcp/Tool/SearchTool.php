<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\ToolDefinition;
use App\Core\Security\Resource;
use App\Model\Search\SearchDocumentType;
use App\Model\Search\SearchFacade;
use App\Model\Search\SearchHit;

final readonly class SearchTool implements McpTool
{
	public function __construct(
		private SearchFacade $search,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'search',
			'Hledat',
			'Fulltextové hledání v zasedáních, bodech programu (důvodová zpráva, návrh usnesení, diskuse), usneseních, připomínkách a plánech. Bez diakritiky i s ní, slova se hledají jako začátky. Výsledek uvádí, kterým nástrojem otevřít detail.',
			[
				'query' => [
					'type' => 'string',
					'description' => 'Hledaná slova, např. „kanalizace Dolní“.',
				],
				'type' => [
					'type' => 'string',
					'enum' => array_map(static fn(SearchDocumentType $type): string => $type->value, SearchDocumentType::cases()),
					'description' => 'Jen jeden druh výsledků.',
				],
				'body_id' => [
					'type' => 'integer',
					'description' => 'Jen orgán (ID z get_overview).',
				],
				'year' => [
					'type' => 'integer',
				],
				'page' => [
					'type' => 'integer',
					'minimum' => 1,
				],
			],
			['query'],
			Resource::Meeting,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$excluded = array_values(array_filter(
			SearchDocumentType::cases(),
			static fn(SearchDocumentType $type): bool => $type->module() !== null && !$context->moduleEnabled($type->module()),
		));
		$page = max(1, $arguments->optionalInt('page', 1) ?? 1);
		$result = $this->search->search(
			$context->municipalityId(),
			$arguments->string('query'),
			$arguments->enum('type', SearchDocumentType::class),
			$arguments->optionalInt('body_id'),
			$arguments->optionalInt('year'),
			$page,
			$excluded,
		);

		return [
			'total' => $result->total,
			'page' => $page,
			'hits' => array_map(static fn(SearchHit $hit): array => [
				'type' => $hit->type->label(),
				'title' => $hit->title->toPlainText(),
				'snippet' => $hit->snippet->toPlainText(),
				'context' => $hit->context,
				'open_with' => self::openWith($hit),
			], $result->hits),
		];
	}

	private static function openWith(SearchHit $hit): ?string
	{
		return match ($hit->type) {
			SearchDocumentType::Meeting => "get_meeting(meeting_id: {$hit->targetId})",
			SearchDocumentType::AgendaItem, SearchDocumentType::ItemComment => "get_agenda_item(item_id: {$hit->targetId})",
			SearchDocumentType::Resolution => "get_resolution(resolution_id: {$hit->entityId})",
			SearchDocumentType::Project, SearchDocumentType::ProjectComment => "get_project(project_id: {$hit->targetId})",
			default => null,
		};
	}
}
