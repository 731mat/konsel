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
use App\Model\Resolution\ResolutionTask;
use App\Model\Resolution\ResolutionTaskFacade;
use App\Model\Resolution\TaskFilter;

final readonly class ListTasksTool implements McpTool
{
	public function __construct(
		private ResolutionTaskFacade $tasks,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'list_tasks',
			'Úkoly z usnesení',
			'Úkoly uložené usneseními: open = otevřené, mine = moje (podle e-mailu uživatele), overdue = po termínu, all = včetně vyřízených.',
			[
				'filter' => [
					'type' => 'string',
					'enum' => array_map(static fn(TaskFilter $filter): string => $filter->value, TaskFilter::cases()),
					'description' => 'Výchozí open.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'description' => 'Výchozí 50.',
				],
			],
			[],
			Resource::Resolution,
			module: Module::ResolutionTasks,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$filter = $arguments->enum('filter', TaskFilter::class) ?? TaskFilter::Open;
		$tasks = $this->tasks->find($context->municipalityId(), $filter, $context->user()->getEmail());

		return [
			'filter' => $filter->label(),
			'total' => count($tasks),
			'tasks' => array_map(
				fn(ResolutionTask $task): array => McpView::task($task, $this->tasks->isOverdue($task)),
				array_slice($tasks, 0, $arguments->limit('limit', 50, 200)),
			),
		];
	}
}
