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
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\MeetingFacade;
use App\Model\Municipality\Module;
use App\Model\People\PeopleFacade;
use App\Model\Project\ProjectFacade;

final readonly class CreateAgendaItemTool implements McpTool
{
	public function __construct(
		private MeetingFacade $meetings,
		private AgendaFacade $agenda,
		private PeopleFacade $people,
		private ProjectFacade $projects,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'create_agenda_item',
			'Přidat bod programu',
			'Přidá bod (nebo podbod) na konec programu zasedání v přípravě. Návrh usnesení je text, o kterém se hlasuje („Zastupitelstvo obce … schvaluje …“); bod bez hlasování (informace) ho nemá.',
			AgendaItemFields::properties() + [
				'meeting_id' => [
					'type' => 'integer',
				],
				'parent_id' => [
					'type' => 'integer',
					'description' => 'ID bodu, pod který se má nový bod vložit jako podbod (hlasuje se o něm zvlášť).',
				],
			],
			['meeting_id', 'title'],
			Resource::Agenda,
			Privilege::Edit,
			writes: true,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$meeting = $context->owned($this->meetings->get($arguments->int('meeting_id')));
		if (!$meeting->canEditProgram()) {
			throw new ToolException('Program lze měnit jen u zasedání v přípravě (před zahájením).');
		}

		$parentId = $arguments->optionalInt('parent_id');
		if ($parentId !== null && $meeting->findAgendaItem($parentId) === null) {
			throw new ToolException('Nadřazený bod v tomto zasedání neexistuje.');
		}

		$rapporteurId = $arguments->optionalInt('rapporteur_id');
		if ($rapporteurId !== null) {
			$context->owned($this->people->getPerson($rapporteurId));
		}

		$item = $this->agenda->addItem($meeting->getId(), new SaveAgendaItem(
			$arguments->string('title'),
			$arguments->optionalString('description'),
			$arguments->optionalString('proposed_resolution'),
			$rapporteurId,
		), $parentId);

		$projectId = $arguments->optionalInt('project_id');
		if ($projectId !== null && $projectId !== 0) {
			$context->requireModule(Module::Plans);
			$context->owned($this->projects->get($projectId));
			$this->projects->linkAgendaItem($item->getId(), $projectId);
		}

		return [
			'created' => McpView::itemSummary($this->agenda->getItem($item->getId())),
			'meeting' => $meeting->getTitle(),
		];
	}
}
