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
use App\Model\Comment\CommentAuthorRef;
use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Municipality\Module;
use App\Model\Project\ProjectFacade;

final readonly class AddCommentTool implements McpTool
{
	public function __construct(
		private CommentFacade $comments,
		private AgendaFacade $agenda,
		private ProjectFacade $projects,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'add_comment',
			'Napsat připomínku',
			'Přidá připomínku (komentář) k bodu programu nebo do diskuse k plánu jménem uživatele. Vidí ji ostatní uživatelé úřadu a zastupitelé. Zadejte buď item_id, nebo project_id.',
			[
				'item_id' => [
					'type' => 'integer',
					'description' => 'Bod programu.',
				],
				'project_id' => [
					'type' => 'integer',
					'description' => 'Plán obce.',
				],
				'text' => [
					'type' => 'string',
					'description' => 'Text připomínky (prostý text, odstavce oddělte prázdným řádkem).',
				],
				'reply_to_id' => [
					'type' => 'integer',
					'description' => 'ID připomínky, na kterou odpovídáte.',
				],
			],
			['text'],
			Resource::Agenda,
			writes: true,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$itemId = $arguments->optionalInt('item_id');
		$projectId = $arguments->optionalInt('project_id');
		if (($itemId === null) === ($projectId === null)) {
			throw new ToolException('Zadejte právě jeden z parametrů item_id nebo project_id.');
		}

		$html = McpView::textToHtml($arguments->string('text'));
		$replyTo = $arguments->optionalInt('reply_to_id');
		if ($itemId !== null) {
			$item = $context->owned($this->agenda->getItem($itemId));
			if (!$item->getMeeting()->canEditAttachments()) {
				throw new ToolException('Zasedání má ověřený zápis, připomínky už přidat nelze.');
			}

			$comment = $this->comments->addByUser($item->getId(), $context->userId(), $html, $replyTo);
		} else {
			$context->requireModule(Module::Plans);
			if (!$context->can(Resource::Project)) {
				throw new ToolException('K plánům nemáte přístup.');
			}

			$project = $context->owned($this->projects->get((int) $projectId));
			$comment = $this->comments->addToProject($project->getId(), CommentAuthorRef::user($context->userId()), $html, $replyTo);
		}

		return [
			'created' => [
				'id' => $comment->getId(),
				'author' => $comment->getAuthorName(),
				'text' => $comment->getBody()->toPlainText(),
			],
		];
	}
}
