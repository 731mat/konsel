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
use App\Model\Meeting\AgendaLockFacade;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Municipality\Module;
use App\Model\People\PeopleFacade;
use App\Model\Project\ProjectFacade;

final readonly class UpdateAgendaItemTool implements McpTool
{
	public function __construct(
		private AgendaFacade $agenda,
		private AgendaLockFacade $locks,
		private PeopleFacade $people,
		private ProjectFacade $projects,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'update_agenda_item',
			'Upravit bod programu',
			'Změní název, důvodovou zprávu, návrh usnesení, předkladatele nebo propojení s plánem u bodu zasedání v přípravě. Mění se jen zadaná pole; prázdný text pole vymaže. Před úpravou si bod přečtěte (get_agenda_item).',
			AgendaItemFields::properties() + [
				'item_id' => [
					'type' => 'integer',
				],
			],
			['item_id'],
			Resource::Agenda,
			Privilege::Edit,
			writes: true,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$item = $context->owned($this->agenda->getItem($arguments->int('item_id')));
		if (!$item->getMeeting()->canEditProgram()) {
			throw new ToolException('Program lze měnit jen u zasedání v přípravě (před zahájením).');
		}

		$this->locks->assertNotLockedByOther($item->getId(), $context->userId());
		$rapporteurId = $arguments->has('rapporteur_id') ? $arguments->optionalInt('rapporteur_id') : $item->getRapporteur()?->getId();
		if ($rapporteurId === 0) {
			$rapporteurId = null;
		}

		if ($rapporteurId !== null) {
			$context->owned($this->people->getPerson($rapporteurId));
		}

		$this->agenda->updateItem($item->getId(), new SaveAgendaItem(
			$arguments->has('title') ? $arguments->string('title') : $item->getTitle(),
			$arguments->has('description') ? $arguments->optionalString('description') : $item->getDescription(),
			$arguments->has('proposed_resolution') ? $arguments->optionalString('proposed_resolution') : $item->getProposedResolution(),
			$rapporteurId,
		));

		if ($arguments->has('project_id')) {
			$context->requireModule(Module::Plans);
			$projectId = $arguments->optionalInt('project_id');
			if ($projectId !== null && $projectId !== 0) {
				$context->owned($this->projects->get($projectId));
			}

			$this->projects->linkAgendaItem($item->getId(), $projectId === 0 ? null : $projectId);
		}

		$updated = $this->agenda->getItem($item->getId());

		return [
			'updated' => McpView::itemSummary($updated),
			'description' => $updated->getDescription(),
			'proposed_resolution' => $updated->getProposedResolution(),
		];
	}
}
