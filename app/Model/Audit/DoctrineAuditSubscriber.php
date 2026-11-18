<?php

declare(strict_types=1);

namespace App\Model\Audit;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Clock\ClockInterface;

/**
 * Historie změn: při flushi zachytí vložení, změny a smazání sledovaných entit a zapíše je do audit_entry
 * (ještě ve stejné transakci – co se vrátí, to v historii nebude).
 */
final class DoctrineAuditSubscriber implements EventSubscriber
{
	/**
	 * @var list<array{object, AuditAction, array<string, array{?string, ?string}>|null, ?int, ?string}>
	 *      [entita, akce, změny, ID u smazané, popis u smazané]
	 */
	private array $pending = [];

	public function __construct(
		private readonly AuditContext $context,
		private readonly ClockInterface $clock,
	) {}

	public function getSubscribedEvents(): array
	{
		return [Events::onFlush, Events::postFlush];
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		$this->pending = [];
		$entityManager = $args->getObjectManager();
		$unitOfWork = $entityManager->getUnitOfWork();
		$enums = static function (object $entity) use ($entityManager): array {
			$types = [];
			foreach ($entityManager->getClassMetadata($entity::class)->fieldMappings as $field => $mapping) {
				if ($mapping->enumType !== null) {
					$types[$field] = $mapping->enumType;
				}
			}

			return $types;
		};

		foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
			if (AuditDescriber::type($entity) !== null) {
				$this->pending[] = [$entity, AuditAction::Created, AuditDescriber::changes($unitOfWork->getEntityChangeSet($entity), $enums($entity)), null, null];
			}
		}

		foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
			if (AuditDescriber::type($entity) === null) {
				continue;
			}

			$changes = AuditDescriber::changes($unitOfWork->getEntityChangeSet($entity), $enums($entity));
			if ($changes !== []) {
				$this->pending[] = [$entity, AuditAction::Updated, $changes, null, null];
			}
		}

		foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
			if (AuditDescriber::type($entity) === null) {
				continue;
			}

			$id = $unitOfWork->getSingleIdentifierValue($entity);
			if (is_int($id) || is_numeric($id)) {
				// po smazání už entita ID ani vazby spolehlivě nemá – popis se vezme teď
				$this->pending[] = [$entity, AuditAction::Deleted, null, (int) $id, AuditDescriber::label($entity)];
			}
		}
	}

	public function postFlush(PostFlushEventArgs $args): void
	{
		$pending = $this->pending;
		$this->pending = [];
		if ($pending === []) {
			return;
		}

		$connection = $args->getObjectManager()->getConnection();
		$actor = $this->context->getActor();
		$now = $this->clock->now()->format('Y-m-d H:i:s');

		foreach ($pending as [$entity, $action, $changes, $deletedId, $deletedLabel]) {
			$type = AuditDescriber::type($entity);
			$municipality = AuditDescriber::municipality($entity);
			$id = $deletedId ?? (method_exists($entity, 'getId') && is_int($entityId = $entity->getId()) ? $entityId : null);
			if ($type === null || $municipality === null || !$municipality->isPersisted() || $id === null) {
				continue;
			}

			$connection->insert('audit_entry', [
				'municipality_id' => $municipality->getId(),
				'meeting_id' => AuditDescriber::meetingId($entity),
				'entity_type' => $type->value,
				'entity_id' => $id,
				'related_id' => AuditDescriber::relatedId($entity),
				'entity_label' => $deletedLabel ?? AuditDescriber::label($entity),
				'action' => $action->value,
				'changes' => $changes === null || $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
				'actor_user_id' => $actor->userId,
				'actor_name' => mb_substr($actor->name, 0, 150),
				'ip' => $actor->ip,
				'created_at' => $now,
			]);
		}
	}
}
