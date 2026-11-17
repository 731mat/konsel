<?php

declare(strict_types=1);

namespace App\Model\Search;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Project\Project;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Udržuje fulltextový index v souladu s daty: změny hledaných entit se propíšou do indexu po flushi,
 * ještě ve stejné transakci (při chybě se vrátí i index).
 */
final class DoctrineSearchIndexSubscriber implements EventSubscriber
{
	/**
	 * @var \SplObjectStorage<object, null>
	 */
	private \SplObjectStorage $pending;

	/**
	 * @var list<\Closure(SearchIndex): void>
	 */
	private array $removals = [];

	public function __construct(
		private readonly SearchIndex $index,
	) {
		$this->pending = new \SplObjectStorage();
	}

	public function getSubscribedEvents(): array
	{
		return [Events::onFlush, Events::postFlush];
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		$this->pending = new \SplObjectStorage();
		$this->removals = [];
		$unitOfWork = $args->getObjectManager()->getUnitOfWork();

		foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
			if (!SearchDocumentFactory::supports($entity)) {
				continue;
			}

			$this->pending->attach($entity);
			// název a datum zasedání jsou v kontextu jeho bodů
			if ($entity instanceof Meeting && $unitOfWork->isScheduledForUpdate($entity)) {
				foreach ($entity->getAgendaItems() as $item) {
					$this->pending->attach($item);
				}
			}
		}

		foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
			$type = SearchDocumentFactory::typeOf($entity);
			if ($type === null) {
				continue;
			}

			// ID po smazání zmizí – zachytí se teď; podřízené záznamy maže databáze kaskádou, z indexu je smažeme tady
			$id = $unitOfWork->getSingleIdentifierValue($entity);
			if (!is_int($id) && !is_numeric($id)) {
				continue;
			}

			$id = (int) $id;
			$this->removals[] = match (true) {
				$entity instanceof Meeting => static fn(SearchIndex $index) => $index->removeByMeeting($id),
				$entity instanceof AgendaItem => static function (SearchIndex $index) use ($id): void {
					$index->remove(SearchDocumentType::AgendaItem, $id);
					$index->removeByAgendaItem($id);
				},
				$entity instanceof Project => static function (SearchIndex $index) use ($id): void {
					$index->remove(SearchDocumentType::Project, $id);
					$index->removeByProject($id);
				},
				default => static fn(SearchIndex $index) => $index->remove($type, $id),
			};
		}
	}

	public function postFlush(): void
	{
		$removals = $this->removals;
		$pending = $this->pending;
		$this->removals = [];
		$this->pending = new \SplObjectStorage();

		foreach ($removals as $removal) {
			$removal($this->index);
		}

		foreach ($pending as $entity) {
			$document = SearchDocumentFactory::create($entity);
			if ($document !== null) {
				$this->index->index($document);
				continue;
			}

			$type = SearchDocumentFactory::typeOf($entity);
			$id = method_exists($entity, 'getId') ? $entity->getId() : null;
			if ($type !== null && is_int($id)) {
				$this->index->remove($type, $id);
			}
		}
	}
}
