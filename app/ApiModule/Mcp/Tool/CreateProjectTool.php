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
use App\Model\Project\Command\SaveProject;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectPriority;
use App\Model\Project\ProjectStatus;

final readonly class CreateProjectTool implements McpTool
{
	public function __construct(
		private ProjectFacade $projects,
		private PeopleFacade $people,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'create_project',
			'Založit plán',
			'Založí dlouhodobý plán obce (neveřejný – zveřejnit ho může úřad v aplikaci).',
			[
				'title' => [
					'type' => 'string',
				],
				'description' => [
					'type' => 'string',
				],
				'priority' => [
					'type' => 'string',
					'enum' => array_map(static fn(ProjectPriority $priority): string => $priority->value, ProjectPriority::cases()),
					'description' => 'Výchozí normal.',
				],
				'status' => [
					'type' => 'string',
					'enum' => array_map(static fn(ProjectStatus $status): string => $status->value, ProjectStatus::cases()),
					'description' => 'Výchozí idea (námět).',
				],
				'responsible_id' => [
					'type' => 'integer',
					'description' => 'Odpovědná osoba (ID z list_people).',
				],
				'estimated_cost' => [
					'type' => 'integer',
					'description' => 'Odhad nákladů v Kč.',
				],
			],
			['title'],
			Resource::Project,
			Privilege::Edit,
			writes: true,
			module: Module::Plans,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$responsibleId = $arguments->optionalInt('responsible_id');
		if ($responsibleId !== null) {
			$context->owned($this->people->getPerson($responsibleId));
		}

		$project = $this->projects->create($context->municipalityId(), new SaveProject(
			$arguments->string('title'),
			$arguments->optionalString('description'),
			$arguments->enum('priority', ProjectPriority::class) ?? ProjectPriority::Normal,
			$responsibleId,
			$arguments->optionalInt('estimated_cost'),
		), $context->user()->getName(), $arguments->enum('status', ProjectStatus::class) ?? ProjectStatus::Idea);

		return [
			'created' => McpView::projectSummary($project),
		];
	}
}
