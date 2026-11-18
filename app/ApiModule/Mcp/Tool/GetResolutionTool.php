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
use App\Model\Resolution\ResolutionFacade;
use App\Model\Resolution\ResolutionTask;
use App\Model\Resolution\ResolutionTaskFacade;

final readonly class GetResolutionTool implements McpTool
{
	public function __construct(
		private ResolutionFacade $resolutions,
		private ResolutionTaskFacade $tasks,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'get_resolution',
			'Detail usnesení',
			'Usnesení: číslo, znění, stav, zasedání a bod, výsledek hlasování a úkoly z usnesení.',
			[
				'resolution_id' => [
					'type' => 'integer',
				],
			],
			['resolution_id'],
			Resource::Resolution,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$resolution = $context->owned($this->resolutions->get($arguments->int('resolution_id')));
		$round = $resolution->getVotingRound();
		$data = McpView::resolution($resolution) + [
			'voting' => $round === null ? null : McpView::votingRound($round),
		];
		if ($context->moduleEnabled(Module::ResolutionTasks)) {
			$data['tasks'] = array_map(
				fn(ResolutionTask $task): array => McpView::task($task, $this->tasks->isOverdue($task)),
				$this->tasks->findByResolution($resolution->getId()),
			);
		}

		return $data;
	}
}
