<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

use App\Model\Shared\Timestampable;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Clock\ClockInterface;

/**
 * Nastavuje createdAt/updatedAt u entit implementujících Timestampable.
 */
final readonly class TimestampableSubscriber implements EventSubscriber
{
	public function __construct(
		private ClockInterface $clock,
	) {}

	public function getSubscribedEvents(): array
	{
		return [Events::prePersist, Events::onFlush];
	}

	public function prePersist(PrePersistEventArgs $args): void
	{
		$entity = $args->getObject();
		if ($entity instanceof Timestampable) {
			$entity->markCreated($this->clock->now());
		}
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		$entityManager = $args->getObjectManager();
		$unitOfWork = $entityManager->getUnitOfWork();

		foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
			if (!$entity instanceof Timestampable) {
				continue;
			}

			$entity->markUpdated($this->clock->now());
			$unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($entity::class), $entity);
		}
	}
}
