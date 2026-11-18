<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\McpView;
use App\ApiModule\Mcp\ToolDefinition;
use App\ApiModule\Mcp\ToolException;
use App\Core\Security\Resource;
use App\Model\Document\MeetingDocumentFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Meeting\MeetingFacade;

final readonly class GetDocumentTool implements McpTool
{
	private const int MaxLength = 80_000;

	public function __construct(
		private MeetingFacade $meetings,
		private MeetingDocumentFacade $documents,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'get_document',
			'Dokument zasedání',
			'Text dokumentu zasedání tak, jak ho systém generuje: invitation = pozvánka, materials = podklady, minutes = zápis, resolutions = usnesení, attendance = prezenční listina. Které jsou k dispozici, uvádí get_meeting.',
			[
				'meeting_id' => [
					'type' => 'integer',
				],
				'type' => [
					'type' => 'string',
					'enum' => array_map(static fn(MeetingDocumentType $type): string => $type->value, MeetingDocumentType::cases()),
				],
			],
			['meeting_id', 'type'],
			Resource::Document,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$meeting = $context->owned($this->meetings->get($arguments->int('meeting_id')));
		$type = $arguments->enum('type', MeetingDocumentType::class) ?? throw new ToolException('Chybí povinný parametr type.');
		if (!$type->isAvailableFor($meeting->getStatus())) {
			throw new ToolException("{$type->label()} zatím není k dispozici (zasedání je ve stavu „{$meeting->getStatus()->label()}“).");
		}

		$text = McpView::plainText($this->documents->renderHtml($meeting, $type, withLogo: false));
		$truncated = mb_strlen($text) > self::MaxLength;

		return [
			'meeting' => $meeting->getTitle(),
			'document' => $type->label(),
			'text' => $truncated ? mb_substr($text, 0, self::MaxLength) . "\n…" : $text,
			'truncated' => $truncated,
		];
	}
}
