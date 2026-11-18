<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\McpView;
use App\ApiModule\Mcp\ToolDefinition;
use App\Core\Security\Resource;
use App\Model\Resolution\Resolution;
use App\Model\Resolution\ResolutionFacade;
use App\Model\Resolution\ResolutionSearch;
use App\Model\Resolution\ResolutionStatus;

final readonly class ListResolutionsTool implements McpTool
{
	public function __construct(
		private ResolutionFacade $resolutions,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'list_resolutions',
			'Seznam usnesení',
			'Usnesení s plným zněním, filtr podle orgánu, roku, stavu (adopted = přijato, rejected = nepřijato, postponed = odloženo, revoked = zrušeno) a textu ve znění nebo čísle.',
			[
				'body_id' => [
					'type' => 'integer',
				],
				'year' => [
					'type' => 'integer',
				],
				'status' => [
					'type' => 'string',
					'enum' => array_map(static fn(ResolutionStatus $status): string => $status->value, ResolutionStatus::cases()),
				],
				'text' => [
					'type' => 'string',
					'description' => 'Hledaný text ve znění nebo číslo usnesení.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'description' => 'Výchozí 30.',
				],
			],
			[],
			Resource::Resolution,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$found = $this->resolutions->search($context->municipalityId(), new ResolutionSearch(
			$arguments->optionalInt('body_id'),
			$arguments->optionalInt('year'),
			$arguments->enum('status', ResolutionStatus::class),
			$arguments->optionalString('text'),
		));

		return [
			'total' => count($found),
			'resolutions' => array_map(
				static fn(Resolution $resolution): array => McpView::resolution($resolution),
				array_slice($found, 0, $arguments->limit('limit', 30, 100)),
			),
		];
	}
}
