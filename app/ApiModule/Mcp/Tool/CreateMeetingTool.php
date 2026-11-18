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
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingType;
use App\Model\People\PeopleFacade;

final readonly class CreateMeetingTool implements McpTool
{
	public function __construct(
		private MeetingFacade $meetings,
		private AgendaFacade $agenda,
		private PeopleFacade $people,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'create_meeting',
			'Založit zasedání',
			'Založí nové zasedání orgánu ve stavu „v přípravě“ (nic se nerozesílá). Volitelně vloží standardní body programu (zahájení, ověřovatelé, program, diskuse, závěr).',
			[
				'body_id' => [
					'type' => 'integer',
					'description' => 'ID orgánu z get_overview.',
				],
				'scheduled_at' => [
					'type' => 'string',
					'description' => 'Datum a čas, např. 2026-12-15 18:00.',
				],
				'place' => [
					'type' => 'string',
					'description' => 'Místo; když chybí, použije se místo posledního zasedání.',
				],
				'type' => [
					'type' => 'string',
					'enum' => array_map(static fn(MeetingType $type): string => $type->value, MeetingType::cases()),
					'description' => 'regular = řádné (výchozí), extraordinary = mimořádné, constituent = ustavující.',
				],
				'insert_standard_items' => [
					'type' => 'boolean',
				],
			],
			['body_id', 'scheduled_at'],
			Resource::Meeting,
			Privilege::Edit,
			writes: true,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$body = $context->owned($this->people->getBody($arguments->int('body_id')));
		$scheduledAt = $arguments->dateTime('scheduled_at', $context->timezone()) ?? throw new ToolException('Chybí povinný parametr scheduled_at.');
		$place = $arguments->optionalString('place') ?? $this->meetings->suggestPlace($context->municipalityId())
			?? throw new ToolException('Zadejte místo konání (parametr place).');
		$meeting = $this->meetings->create($body->getId(), new SaveMeeting(
			$arguments->enum('type', MeetingType::class) ?? MeetingType::Regular,
			$scheduledAt,
			$place,
		));
		$added = $arguments->bool('insert_standard_items') ? $this->agenda->insertStandardItems($meeting->getId()) : 0;

		return [
			'created' => McpView::meetingSummary($this->meetings->get($meeting->getId())),
			'standard_items_added' => $added,
		];
	}
}
