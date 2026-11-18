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
use App\Model\Document\MeetingDocumentType;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Resolution\Resolution;
use App\Model\Resolution\ResolutionFacade;

final readonly class GetMeetingTool implements McpTool
{
	public function __construct(
		private MeetingFacade $meetings,
		private AttachmentFacade $attachments,
		private CommentFacade $comments,
		private ResolutionFacade $resolutions,
		private ProceedingsFacade $proceedings,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'get_meeting',
			'Detail zasedání',
			'Zasedání s programem (body a podbody s ID, předkladatel, počty příloh a připomínek), funkcemi (předsedající, zapisovatel, ověřovatelé), docházkou, usneseními a seznamem dokumentů pro get_document.',
			[
				'meeting_id' => [
					'type' => 'integer',
				],
			],
			['meeting_id'],
			Resource::Meeting,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$meeting = $context->owned($this->meetings->get($arguments->int('meeting_id')));
		$attachmentCounts = $this->attachments->countByMeeting($meeting);
		$commentCounts = $this->comments->countByMeeting($meeting);
		$data = McpView::meetingSummary($meeting) + [
			'chairperson' => McpView::person($meeting->getChairperson()),
			'recorder' => McpView::person($meeting->getRecorder()),
			'verifiers' => array_map(McpView::person(...), $meeting->getVerifiers()),
			'started_at' => McpView::dateTime($meeting->getStartedAt()),
			'ended_at' => McpView::dateTime($meeting->getEndedAt()),
			'minutes_deadline' => McpView::date($meeting->getMinutesDeadline()),
			'agenda' => array_map(
				static fn(AgendaItem $item): array => McpView::itemSummary($item, $attachmentCounts, $commentCounts),
				$meeting->getAgendaItems(),
			),
		];

		if (!$meeting->getStatus()->isPlanning()) {
			$quorum = $this->proceedings->attendance($meeting->getId())->quorum;
			$data['attendance'] = [
				'present' => $quorum->present,
				'members' => $quorum->members,
				'quorum_reached' => $quorum->isReached(),
			];
		}

		$data['resolutions'] = array_map(
			static fn(Resolution $resolution): array => McpView::resolution($resolution, withText: false),
			$this->resolutions->findByMeeting($meeting->getId()),
		);
		$data['documents'] = array_values(array_map(
			static fn(MeetingDocumentType $type): array => [
				'type' => $type->value,
				'name' => $type->label(),
			],
			array_filter(MeetingDocumentType::cases(), static fn(MeetingDocumentType $type): bool => $type->isAvailableFor($meeting->getStatus())),
		));

		return $data;
	}
}
