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
use App\Model\Resolution\ResolutionTaskFacade;

final readonly class UpdateResolutionTaskTool implements McpTool
{
	public function __construct(
		private ResolutionTaskFacade $tasks,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'update_resolution_task',
			'Vyřídit úkol z usnesení',
			'Označí úkol jako splněný (complete), zrušený (cancel) nebo ho znovu otevře (reopen), volitelně s poznámkou.',
			[
				'task_id' => [
					'type' => 'integer',
				],
				'action' => [
					'type' => 'string',
					'enum' => ['complete', 'cancel', 'reopen'],
				],
				'note' => [
					'type' => 'string',
				],
			],
			['task_id', 'action'],
			Resource::Resolution,
			Privilege::Edit,
			writes: true,
			module: Module::ResolutionTasks,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$task = $context->owned($this->tasks->get($arguments->int('task_id')));
		$note = $arguments->optionalString('note');
		match ($arguments->string('action')) {
			'complete' => $this->tasks->complete($task->getId(), $note),
			'cancel' => $this->tasks->cancel($task->getId(), $note),
			'reopen' => $this->tasks->reopen($task->getId()),
			default => throw new ToolException('Parametr action musí být complete, cancel nebo reopen.'),
		};
		$updated = $this->tasks->get($task->getId());

		return [
			'updated' => McpView::task($updated, $this->tasks->isOverdue($updated)),
		];
	}
}
