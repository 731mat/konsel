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
use App\Model\Meeting\MeetingStatus;

final readonly class ListMeetingsTool implements McpTool
{
	public function __construct(
		private MeetingFacade $meetings,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'list_meetings',
			'Seznam zasedání',
			'Zasedání obce s filtrem podle orgánu, roku a stavu (draft = v přípravě, shared = sdílené se zastupiteli, in_progress = probíhá, closed = ukončené, minutes_approved = zápis ověřen).',
			[
				'body_id' => [
					'type' => 'integer',
					'description' => 'ID orgánu z get_overview.',
				],
				'year' => [
					'type' => 'integer',
				],
				'status' => [
					'type' => 'string',
					'enum' => array_map(static fn(MeetingStatus $status): string => $status->value, MeetingStatus::cases()),
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'description' => 'Výchozí 30.',
				],
			],
			[],
			Resource::Meeting,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$meetings = $this->meetings->find(
			$context->municipalityId(),
			$arguments->optionalInt('body_id'),
			$arguments->optionalInt('year'),
			$arguments->enum('status', MeetingStatus::class),
		);
		$limit = $arguments->limit('limit', 30, 100);

		return [
			'total' => count($meetings),
			'meetings' => array_map(McpView::meetingSummary(...), array_slice($meetings, 0, $limit)),
		];
	}
}
