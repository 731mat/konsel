<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\McpView;
use App\ApiModule\Mcp\ToolDefinition;
use App\Core\Security\Resource;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\VotingFacade;
use App\Model\Municipality\Module;
use App\Model\Resolution\Resolution;
use App\Model\Resolution\ResolutionFacade;

final readonly class GetAgendaItemTool implements McpTool
{
	public function __construct(
		private AgendaFacade $agenda,
		private AttachmentFacade $attachments,
		private CommentFacade $comments,
		private VotingFacade $voting,
		private ResolutionFacade $resolutions,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'get_agenda_item',
			'Detail bodu programu',
			'Bod programu celý: důvodová zpráva, návrh usnesení, diskuse, podbody, přílohy (názvy), připomínky zastupitelů a úřadu, hlasování a přijatá usnesení.',
			[
				'item_id' => [
					'type' => 'integer',
				],
			],
			['item_id'],
			Resource::Agenda,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$item = $context->owned($this->agenda->getItem($arguments->int('item_id')));
		$meeting = $item->getMeeting();
		$project = $context->moduleEnabled(Module::Plans) ? $item->getProject() : null;

		return [
			'id' => $item->getId(),
			'number' => $item->getNumber(),
			'title' => $item->getTitle(),
			'meeting' => McpView::meetingSummary($meeting),
			'parent' => $item->getParent() === null ? null : [
				'id' => $item->getParent()->getId(),
				'number' => $item->getParent()->getNumber(),
				'title' => $item->getParent()->getTitle(),
			],
			'sub_items' => array_map(static fn(AgendaItem $sub): array => [
				'id' => $sub->getId(),
				'number' => $sub->getNumber(),
				'title' => $sub->getTitle(),
			], $meeting->getSubItems($item)),
			'rapporteur' => McpView::person($item->getRapporteur()),
			'description' => $item->getDescription(),
			'proposed_resolution' => $item->getProposedResolution(),
			'discussion' => $item->getDiscussion(),
			'added_during_meeting' => $item->isAddedDuringMeeting(),
			'project' => $project === null ? null : [
				'id' => $project->getId(),
				'title' => $project->getTitle(),
			],
			'attachments' => $context->can(Resource::Attachment) ? array_map(McpView::attachment(...), $this->attachments->findByAgendaItem($item->getId())) : [],
			'comments' => array_map(McpView::thread(...), $this->comments->findThreads($item->getId())),
			'votings' => array_map(McpView::votingRound(...), $this->voting->findByMeeting($meeting->getId())[$item->getId()] ?? []),
			'resolutions' => array_values(array_map(
				static fn(Resolution $resolution): array => McpView::resolution($resolution),
				array_filter($this->resolutions->findByMeeting($meeting->getId()), static fn(Resolution $resolution): bool => $resolution->getAgendaItem() === $item),
			)),
		];
	}
}
