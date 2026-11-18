<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\McpView;
use App\ApiModule\Mcp\ToolDefinition;
use App\Core\Security\Resource;
use App\Model\Meeting\MeetingFacade;
use App\Model\Municipality\Module;
use App\Model\People\PeopleFacade;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectStatus;
use App\Model\Resolution\ResolutionTaskFacade;
use App\Model\Resolution\TaskFilter;

final readonly class GetOverviewTool implements McpTool
{
	public function __construct(
		private MeetingFacade $meetings,
		private PeopleFacade $people,
		private ResolutionTaskFacade $tasks,
		private ProjectFacade $projects,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'get_overview',
			'Přehled obce',
			'Výchozí bod: obec, přihlášený uživatel, orgány aktuálního volebního období (s ID), nejbližší a poslední zasedání, počty úkolů a plánů. Zavolejte jako první.',
			[],
			[],
			Resource::Dashboard,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$municipalityId = $context->municipalityId();
		$term = $this->people->findCurrentTerm($municipalityId);
		$overview = [
			'municipality' => $context->municipality()->getName(),
			'user' => [
				'name' => $context->user()->getName(),
				'role' => $context->user()->getRole()->label(),
				'connection_scope' => $context->token->getScope()->label(),
			],
			'today' => McpView::date($context->now()),
			'term' => $term?->getName(),
			'bodies' => $term === null ? [] : array_map(static fn(\App\Model\People\Body $body): array => [
				'id' => $body->getId(),
				'name' => $body->getName(),
				'type' => $body->getType()->label(),
				'seats' => $body->getSeatCount(),
			], $this->people->findBodies($term->getId())),
			'upcoming_meetings' => array_map(McpView::meetingSummary(...), $this->meetings->findUnfinished($municipalityId, 5)),
			'recent_meetings' => array_map(McpView::meetingSummary(...), $this->meetings->findFinished($municipalityId, 5)),
		];

		if ($context->moduleEnabled(Module::ResolutionTasks)) {
			$overview['open_tasks'] = count($this->tasks->find($municipalityId, TaskFilter::Open));
			$overview['overdue_tasks'] = count($this->tasks->find($municipalityId, TaskFilter::Overdue));
		}

		if ($context->moduleEnabled(Module::Plans)) {
			$counts = $this->projects->countByStatus($municipalityId);
			$overview['plans_by_status'] = array_filter(array_combine(
				array_map(static fn(ProjectStatus $status): string => $status->label(), ProjectStatus::cases()),
				array_map(static fn(ProjectStatus $status): int => $counts[$status->value] ?? 0, ProjectStatus::cases()),
			), static fn(int $count): bool => $count > 0);
		}

		$overview['modules'] = [
			Module::ResolutionTasks->label() => $context->moduleEnabled(Module::ResolutionTasks),
			Module::Plans->label() => $context->moduleEnabled(Module::Plans),
		];

		return $overview;
	}
}
