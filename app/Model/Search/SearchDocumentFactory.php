<?php

declare(strict_types=1);

namespace App\Model\Search;

use App\Model\Comment\Comment;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Project\Project;
use App\Model\Resolution\Resolution;
use App\Model\Template\MeetingTemplate;
use App\Model\Template\ResolutionTemplate;

/**
 * Převod entit na záznamy indexu: co se v nich hledá (název, obsah) a kam výsledek vede.
 */
final class SearchDocumentFactory
{
	/**
	 * Třídy, jejichž změna mění index.
	 */
	public const array IndexedClasses = [
		Meeting::class,
		AgendaItem::class,
		Resolution::class,
		Comment::class,
		Project::class,
		ResolutionTemplate::class,
		MeetingTemplate::class,
	];

	public static function supports(object $entity): bool
	{
		foreach (self::IndexedClasses as $class) {
			if ($entity instanceof $class) {
				return true;
			}
		}

		return false;
	}

	public static function typeOf(object $entity): ?SearchDocumentType
	{
		return match (true) {
			$entity instanceof Meeting => SearchDocumentType::Meeting,
			$entity instanceof AgendaItem => SearchDocumentType::AgendaItem,
			$entity instanceof Resolution => SearchDocumentType::Resolution,
			$entity instanceof Comment => $entity->getProject() !== null ? SearchDocumentType::ProjectComment : SearchDocumentType::ItemComment,
			$entity instanceof Project => SearchDocumentType::Project,
			$entity instanceof ResolutionTemplate => SearchDocumentType::ResolutionTemplate,
			$entity instanceof MeetingTemplate => SearchDocumentType::MeetingTemplate,
			default => null,
		};
	}

	/**
	 * @return SearchDocument|null null = do indexu nepatří (smazaný komentář)
	 */
	public static function create(object $entity): ?SearchDocument
	{
		return match (true) {
			$entity instanceof Meeting => self::meeting($entity),
			$entity instanceof AgendaItem => self::agendaItem($entity),
			$entity instanceof Resolution => self::resolution($entity),
			$entity instanceof Comment => self::comment($entity),
			$entity instanceof Project => self::project($entity),
			$entity instanceof ResolutionTemplate => new SearchDocument(
				SearchDocumentType::ResolutionTemplate,
				$entity->getId(),
				$entity->getId(),
				$entity->getMunicipality()->getId(),
				$entity->getTitle(),
				$entity->getText(),
				$entity->getCategory(),
			),
			$entity instanceof MeetingTemplate => new SearchDocument(
				SearchDocumentType::MeetingTemplate,
				$entity->getId(),
				$entity->getId(),
				$entity->getMunicipality()->getId(),
				$entity->getName(),
				self::join([$entity->getNote(), ...array_map(static fn($item): string => $item->title, $entity->getItems())]),
				$entity->getMeetingType()->label(),
			),
			default => null,
		};
	}

	private static function meeting(Meeting $meeting): SearchDocument
	{
		return new SearchDocument(
			SearchDocumentType::Meeting,
			$meeting->getId(),
			$meeting->getId(),
			$meeting->getMunicipality()->getId(),
			"{$meeting->getTitle()} – {$meeting->getBody()->getName()}",
			self::join([$meeting->getPlace(), $meeting->getMinutesNote()]),
			self::meetingContext($meeting),
			meetingId: $meeting->getId(),
			bodyId: $meeting->getBody()->getId(),
			year: (int) $meeting->getScheduledAt()->format('Y'),
		);
	}

	private static function agendaItem(AgendaItem $item): SearchDocument
	{
		$meeting = $item->getMeeting();

		return new SearchDocument(
			SearchDocumentType::AgendaItem,
			$item->getId(),
			$item->getId(),
			$item->getMunicipality()->getId(),
			$item->getTitle(),
			self::join([$item->getDescription(), $item->getProposedResolution(), $item->getDiscussion()]),
			"{$item->getPosition()}. bod · " . self::meetingContext($meeting),
			meetingId: $meeting->getId(),
			agendaItemId: $item->getId(),
			projectId: $item->getProject()?->getId(),
			bodyId: $meeting->getBody()->getId(),
			year: (int) $meeting->getScheduledAt()->format('Y'),
		);
	}

	private static function resolution(Resolution $resolution): SearchDocument
	{
		$meeting = $resolution->getMeeting();

		return new SearchDocument(
			SearchDocumentType::Resolution,
			$resolution->getId(),
			$resolution->getId(),
			$resolution->getMunicipality()->getId(),
			"Usnesení č. {$resolution->getNumber()} – {$resolution->getAgendaItem()->getTitle()}",
			self::join([$resolution->getText(), $resolution->getStatusNote()]),
			mb_ucfirst($resolution->getStatus()->label()) . ' · ' . self::meetingContext($meeting),
			meetingId: $meeting->getId(),
			agendaItemId: $resolution->getAgendaItem()->getId(),
			bodyId: $resolution->getBody()->getId(),
			year: (int) $resolution->getAdoptedAt()->format('Y'),
		);
	}

	private static function comment(Comment $comment): ?SearchDocument
	{
		if ($comment->isDeleted()) {
			return null;
		}

		$project = $comment->getProject();
		$item = $comment->getAgendaItem();
		if ($project !== null) {
			return new SearchDocument(
				SearchDocumentType::ProjectComment,
				$comment->getId(),
				$project->getId(),
				$comment->getMunicipality()->getId(),
				"Komentář – {$comment->getAuthorName()}",
				$comment->getBody()->toPlainText(),
				$project->getTitle(),
				projectId: $project->getId(),
				year: (int) $comment->getCreatedAt()->format('Y'),
			);
		}

		if ($item === null) {
			return null;
		}

		$meeting = $item->getMeeting();

		return new SearchDocument(
			SearchDocumentType::ItemComment,
			$comment->getId(),
			$item->getId(),
			$comment->getMunicipality()->getId(),
			"Komentář – {$comment->getAuthorName()}",
			$comment->getBody()->toPlainText(),
			"{$item->getTitle()} · " . self::meetingContext($meeting),
			meetingId: $meeting->getId(),
			agendaItemId: $item->getId(),
			bodyId: $meeting->getBody()->getId(),
			year: (int) $meeting->getScheduledAt()->format('Y'),
		);
	}

	private static function project(Project $project): SearchDocument
	{
		return new SearchDocument(
			SearchDocumentType::Project,
			$project->getId(),
			$project->getId(),
			$project->getMunicipality()->getId(),
			$project->getTitle(),
			(string) $project->getDescription(),
			$project->getStatus()->label(),
			projectId: $project->getId(),
			year: (int) $project->getCreatedAt()->format('Y'),
		);
	}

	private static function meetingContext(Meeting $meeting): string
	{
		return "{$meeting->getTitle()} · {$meeting->getBody()->getName()} · {$meeting->getScheduledAt()->format('j. n. Y')}";
	}

	/**
	 * @param list<?string> $parts
	 */
	private static function join(array $parts): string
	{
		return implode("\n\n", array_filter(array_map(static fn(?string $part): string => trim((string) $part), $parts), static fn(string $part): bool => $part !== ''));
	}
}
