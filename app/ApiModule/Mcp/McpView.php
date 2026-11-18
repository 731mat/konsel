<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

use App\Model\Attachment\Attachment;
use App\Model\Comment\Comment;
use App\Model\Comment\CommentThread;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\VoteChoice;
use App\Model\Meeting\VotingRound;
use App\Model\People\Person;
use App\Model\Project\Project;
use App\Model\Resolution\Resolution;
use App\Model\Resolution\ResolutionTask;

/**
 * Převod entit na data pro AI. Klíče anglicky (stabilní), hodnoty stavů česky (label).
 * Kontakty osob (e-mail, telefon) se AI neposílají.
 */
final class McpView
{
	public static function dateTime(?\DateTimeImmutable $value): ?string
	{
		return $value?->format('Y-m-d H:i');
	}

	public static function date(?\DateTimeImmutable $value): ?string
	{
		return $value?->format('Y-m-d');
	}

	public static function person(?Person $person): ?string
	{
		return $person?->getFullName();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function meetingSummary(Meeting $meeting): array
	{
		return [
			'id' => $meeting->getId(),
			'title' => $meeting->getTitle(),
			'body' => $meeting->getBody()->getName(),
			'body_id' => $meeting->getBody()->getId(),
			'type' => $meeting->getType()->label(),
			'scheduled_at' => self::dateTime($meeting->getScheduledAt()),
			'place' => $meeting->getPlace(),
			'status' => $meeting->getStatus()->label(),
			'program_editable' => $meeting->canEditProgram(),
		];
	}

	/**
	 * @param array<int, int> $attachmentCounts
	 * @param array<int, int> $commentCounts
	 * @return array<string, mixed>
	 */
	public static function itemSummary(AgendaItem $item, array $attachmentCounts = [], array $commentCounts = []): array
	{
		return [
			'id' => $item->getId(),
			'number' => $item->getNumber(),
			'title' => $item->getTitle(),
			'parent_id' => $item->getParent()?->getId(),
			'rapporteur' => self::person($item->getRapporteur()),
			'has_proposed_resolution' => $item->getProposedResolution() !== null,
			'attachments' => $attachmentCounts[$item->getId()] ?? 0,
			'comments' => $commentCounts[$item->getId()] ?? 0,
			'project' => $item->getProject()?->getTitle(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function resolution(Resolution $resolution, bool $withText = true): array
	{
		return [
			'id' => $resolution->getId(),
			'number' => $resolution->getNumber(),
			'status' => $resolution->getStatus()->label(),
			'adopted_at' => self::date($resolution->getAdoptedAt()),
			'body' => $resolution->getBody()->getName(),
			'meeting_id' => $resolution->getMeeting()->getId(),
			'meeting' => $resolution->getMeeting()->getTitle(),
			'agenda_item_id' => $resolution->getAgendaItem()->getId(),
			'agenda_item' => $resolution->getAgendaItem()->getNumber() . '. ' . $resolution->getAgendaItem()->getTitle(),
			...($withText ? [
				'text' => $resolution->getText(),
			] : []),
			'status_note' => $resolution->getStatusNote(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function task(ResolutionTask $task, bool $overdue): array
	{
		return [
			'id' => $task->getId(),
			'description' => $task->getDescription(),
			'assignee' => self::person($task->getAssignee()),
			'due_date' => self::date($task->getDueDate()),
			'status' => $task->getStatus()->label(),
			'overdue' => $overdue,
			'note' => $task->getNote(),
			'resolution_id' => $task->getResolution()->getId(),
			'resolution' => $task->getResolution()->getNumber(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function projectSummary(Project $project): array
	{
		return [
			'id' => $project->getId(),
			'title' => $project->getTitle(),
			'status' => $project->getStatus()->label(),
			'status_code' => $project->getStatus()->value,
			'priority' => $project->getPriority()->label(),
			'responsible' => self::person($project->getResponsible()),
			'estimated_cost_czk' => $project->getEstimatedCost(),
			'public' => $project->isPublic(),
			'citizen_proposal' => $project->isCitizenProposal(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function attachment(Attachment $attachment): array
	{
		$file = $attachment->getFile();

		return [
			'id' => $attachment->getId(),
			'title' => $attachment->getTitle(),
			'file_name' => $file->originalName,
			'size_kb' => (int) ceil($file->size / 1024),
			'visible_to_members' => $attachment->isPublicForMembers(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function thread(CommentThread $thread): array
	{
		return [
			...self::comment($thread),
			'replies' => array_map(static fn(Comment $reply): array => self::commentData($reply), $thread->replies),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function votingRound(VotingRound $round): array
	{
		$votes = [];
		foreach (VoteChoice::cases() as $choice) {
			$votes[$choice->label()] = $round->count($choice);
		}

		return [
			'type' => $round->getType()->label(),
			'proposal' => $round->getProposalText(),
			'candidate' => self::person($round->getCandidate()),
			'result' => $round->getResult()?->label($round->getType()),
			'votes' => $votes,
			'closed_at' => self::dateTime($round->getClosedAt()),
		];
	}

	/**
	 * Prostý text z HTML (komentáře, vykreslené dokumenty).
	 */
	public static function plainText(string $html): string
	{
		$html = preg_replace('~<(style|script|head|title)\b.*?</\1>~is', '', $html) ?? $html;
		$text = preg_replace(['~<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>~i', '~<li\b[^>]*>~i', '~</t[dh]>~i'], ["\n", '• ', ' | '], $html) ?? $html;
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace(["~[ \t\u{A0}]+~u", "~ *\n *~", "~\n{3,}~"], [' ', "\n", "\n\n"], $text) ?? $text;

		return trim($text);
	}

	/**
	 * Text od AI → HTML pro komentář (odstavce, řádky); fasáda ho ještě pročistí.
	 */
	public static function textToHtml(string $text): string
	{
		$paragraphs = preg_split("~\n\\s*\n~", trim(str_replace("\r\n", "\n", $text)));
		$paragraphs = $paragraphs === false ? [] : $paragraphs;

		return implode('', array_map(
			static fn(string $paragraph): string => '<p>' . nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>',
			$paragraphs,
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function comment(CommentThread $thread): array
	{
		return self::commentData($thread->comment);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function commentData(Comment $comment): array
	{
		return [
			'id' => $comment->getId(),
			'author' => $comment->getAuthorName(),
			'from_member' => $comment->isFromMember(),
			'created_at' => self::dateTime($comment->getCreatedAt()),
			'text' => $comment->isDeleted() ? '(smazáno)' : $comment->getBody()->toPlainText(),
			'attachments' => array_map(static fn(Attachment $attachment): string => $attachment->getTitle(), $comment->getAttachments()),
		];
	}
}
