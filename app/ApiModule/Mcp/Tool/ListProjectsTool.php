<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\McpView;
use App\ApiModule\Mcp\ToolDefinition;
use App\Core\Security\Resource;
use App\Model\Municipality\Module;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectStatus;

final readonly class ListProjectsTool implements McpTool
{
	public function __construct(
		private ProjectFacade $projects,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'list_projects',
			'Plány obce',
			'Dlouhodobé plány a záměry obce (např. nová školka) a podněty občanů: fáze, priorita, odpovědná osoba, odhad nákladů.',
			[
				'status' => [
					'type' => 'string',
					'enum' => array_map(static fn(ProjectStatus $status): string => $status->value, ProjectStatus::cases()),
				],
				'only_active' => [
					'type' => 'boolean',
					'description' => 'Jen rozpracované (bez hotových, odložených a zamítnutých).',
				],
			],
			[],
			Resource::Project,
			module: Module::Plans,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$projects = $this->projects->find($context->municipalityId(), $arguments->enum('status', ProjectStatus::class), $arguments->bool('only_active'));

		return [
			'total' => count($projects),
			'projects' => array_map(McpView::projectSummary(...), $projects),
		];
	}
}
