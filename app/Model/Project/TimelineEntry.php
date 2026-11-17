<?php

declare(strict_types=1);

namespace App\Model\Project;

use App\Model\Attachment\Attachment;
use App\Model\Comment\CommentThread;
use App\Model\Meeting\AgendaItem;
use App\Model\Resolution\Resolution;

/**
 * Jedna událost ve vlákně plánu.
 */
final readonly class TimelineEntry
{
	public const string Created = 'created';

	public const string Status = 'status';

	public const string Agenda = 'agenda';

	public const string Thread = 'thread';

	public const string File = 'file';

	/**
	 * @param list<Resolution> $resolutions usnesení přijatá k bodu (u projednání na zasedání)
	 */
	private function __construct(
		public string $kind,
		public \DateTimeImmutable $at,
		public ?ProjectStatusChange $statusChange = null,
		public ?AgendaItem $agendaItem = null,
		public ?CommentThread $thread = null,
		public ?Attachment $file = null,
		public array $resolutions = [],
	) {}

	public static function created(Project $project): self
	{
		return new self(self::Created, $project->getCreatedAt());
	}

	public static function status(ProjectStatusChange $change): self
	{
		return new self(self::Status, $change->getChangedAt(), statusChange: $change);
	}

	/**
	 * @param list<Resolution> $resolutions
	 */
	public static function agenda(AgendaItem $item, array $resolutions = []): self
	{
		return new self(self::Agenda, $item->getMeeting()->getScheduledAt(), agendaItem: $item, resolutions: $resolutions);
	}

	public static function thread(CommentThread $thread): self
	{
		return new self(self::Thread, $thread->comment->getCreatedAt(), thread: $thread);
	}

	public static function file(Attachment $attachment): self
	{
		return new self(self::File, $attachment->getCreatedAt(), file: $attachment);
	}
}
