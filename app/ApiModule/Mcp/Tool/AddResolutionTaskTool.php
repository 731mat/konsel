<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\McpView;
use App\ApiModule\Mcp\ToolDefinition;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Municipality\Module;
use App\Model\People\PeopleFacade;
use App\Model\Resolution\Command\SaveResolutionTask;
use App\Model\Resolution\ResolutionFacade;
use App\Model\Resolution\ResolutionTaskFacade;

final readonly class AddResolutionTaskTool implements McpTool
{
	public function __construct(
		private ResolutionFacade $resolutions,
		private ResolutionTaskFacade $tasks,
		private PeopleFacade $people,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'add_resolution_task',
			'Přidat úkol z usnesení',
			'Přidá k přijatému usnesení úkol s odpovědnou osobou a termínem.',
			[
				'resolution_id' => [
					'type' => 'integer',
				],
				'description' => [
					'type' => 'string',
					'description' => 'Co se má udělat, např. „zajistit podpis smlouvy o dílo“.',
				],
				'assignee_id' => [
					'type' => 'integer',
					'description' => 'Odpovědná osoba (ID z list_people).',
				],
				'due_date' => [
					'type' => 'string',
					'description' => 'Termín RRRR-MM-DD.',
				],
			],
			['resolution_id', 'description'],
			Resource::Resolution,
			Privilege::Edit,
			writes: true,
			module: Module::ResolutionTasks,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$resolution = $context->owned($this->resolutions->get($arguments->int('resolution_id')));
		$assigneeId = $arguments->optionalInt('assignee_id');
		if ($assigneeId !== null) {
			$context->owned($this->people->getPerson($assigneeId));
		}

		$task = $this->tasks->add($resolution->getId(), new SaveResolutionTask(
			$arguments->string('description'),
			$assigneeId,
			$arguments->dateTime('due_date', $context->timezone())?->setTime(0, 0),
		));

		return [
			'created' => McpView::task($task, $this->tasks->isOverdue($task)),
		];
	}
}
