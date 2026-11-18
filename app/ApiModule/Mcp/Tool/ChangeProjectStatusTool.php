<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\McpView;
use App\ApiModule\Mcp\ToolDefinition;
use App\ApiModule\Mcp\ToolException;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Municipality\Module;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectStatus;

final readonly class ChangeProjectStatusTool implements McpTool
{
	public function __construct(
		private ProjectFacade $projects,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'change_project_status',
			'Změnit fázi plánu',
			'Posune plán do jiné fáze (námět → příprava → projekt → schváleno → realizace → hotovo, nebo odloženo/zamítnuto) s volitelnou poznámkou; změna se objeví ve vlákně plánu.',
			[
				'project_id' => [
					'type' => 'integer',
				],
				'status' => [
					'type' => 'string',
					'enum' => array_map(static fn(ProjectStatus $status): string => $status->value, ProjectStatus::cases()),
				],
				'note' => [
					'type' => 'string',
				],
			],
			['project_id', 'status'],
			Resource::Project,
			Privilege::Edit,
			writes: true,
			module: Module::Plans,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$project = $context->owned($this->projects->get($arguments->int('project_id')));
		$status = $arguments->enum('status', ProjectStatus::class) ?? throw new ToolException('Chybí povinný parametr status.');
		$this->projects->changeStatus($project->getId(), $status, $context->user()->getName(), $arguments->optionalString('note'));

		return [
			'updated' => McpView::projectSummary($this->projects->get($project->getId())),
		];
	}
}
