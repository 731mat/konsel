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
use App\Model\Project\TimelineEntry;
use App\Model\Resolution\Resolution;

final readonly class GetProjectTool implements McpTool
{
	public function __construct(
		private ProjectFacade $projects,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'get_project',
			'Detail plánu',
			'Plán obce s popisem a celým vláknem chronologicky: vznik, změny fáze, projednání na zasedáních (návrh usnesení a usnesení), soubory a diskuse.',
			[
				'project_id' => [
					'type' => 'integer',
				],
			],
			['project_id'],
			Resource::Project,
			module: Module::Plans,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$project = $context->owned($this->projects->get($arguments->int('project_id')));

		return McpView::projectSummary($project) + [
			'description' => $project->getDescription(),
			'created_by' => $project->getCreatedByName(),
			'timeline' => array_map(self::entry(...), $this->projects->timeline($project->getId())),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function entry(TimelineEntry $entry): array
	{
		$data = [
			'at' => McpView::dateTime($entry->at),
			'kind' => match ($entry->kind) {
				TimelineEntry::Created => 'vznik',
				TimelineEntry::Status => 'změna fáze',
				TimelineEntry::Agenda => 'projednání na zasedání',
				TimelineEntry::Thread => 'diskuse',
				TimelineEntry::File => 'soubor',
				default => $entry->kind,
			},
		];

		if ($entry->statusChange !== null) {
			$data['from'] = $entry->statusChange->getFromStatus()->label();
			$data['to'] = $entry->statusChange->getToStatus()->label();
			$data['by'] = $entry->statusChange->getChangedByName();
			$data['note'] = $entry->statusChange->getNote();
		}

		if ($entry->agendaItem !== null) {
			$data['meeting'] = $entry->agendaItem->getMeeting()->getTitle();
			$data['agenda_item_id'] = $entry->agendaItem->getId();
			$data['agenda_item'] = $entry->agendaItem->getNumber() . '. ' . $entry->agendaItem->getTitle();
			$data['proposed_resolution'] = $entry->agendaItem->getProposedResolution();
			$data['resolutions'] = array_map(static fn(Resolution $resolution): string => "{$resolution->getNumber()} ({$resolution->getStatus()->label()}): {$resolution->getText()}", $entry->resolutions);
		}

		if ($entry->thread !== null) {
			$data['thread'] = McpView::thread($entry->thread);
		}

		if ($entry->file !== null) {
			$data['file'] = $entry->file->getTitle();
		}

		return $data;
	}
}
