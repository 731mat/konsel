<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Shared\TransactionManager;
use App\Model\User\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Zámky bodů na obrazovce průběhu: kdo upravuje diskusi, ostatní ji mají jen ke čtení (ADR A14).
 */
final readonly class AgendaLockFacade
{
	public function __construct(
		private AgendaItemRepository $items,
		private MeetingRepository $meetings,
		private UserRepository $users,
		private AgendaItemLockRepository $locks,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * Získá nebo obnoví zámek (heartbeat). Vypršelý zámek jiného uživatele převezme.
	 *
	 * @throws LockedException bod drží někdo jiný
	 */
	public function acquire(int $itemId, int $userId): AgendaItemLock
	{
		return $this->transaction->run(function () use ($itemId, $userId): AgendaItemLock {
			$item = $this->items->get($itemId);
			$user = $this->users->get($userId);
			$now = $this->clock->now();
			$lock = $this->locks->findByItem($item);
			if ($lock === null) {
				$lock = new AgendaItemLock($item, $user, $now);
				$this->locks->add($lock);

				return $lock;
			}

			if (!$lock->isExpired($now) && $lock->getUser() !== $user) {
				throw new LockedException($lock->getUser()->getName(), $lock->getExpiresAt());
			}

			$lock->renew($user, $now);

			return $lock;
		});
	}

	/**
	 * Uvolní zámek, pokud ho uživatel drží (uložení, odchod ze stránky).
	 */
	public function release(int $itemId, int $userId): void
	{
		$this->transaction->run(function () use ($itemId, $userId): void {
			$lock = $this->locks->findByItem($this->items->get($itemId));
			if ($lock !== null && $lock->getUser()->getId() === $userId) {
				$this->locks->remove($lock);
			}
		});
	}

	/**
	 * Platné zámky zasedání, které drží někdo jiný než uživatel.
	 *
	 * @return array<int, AgendaItemLock> ID bodu → zámek
	 */
	public function findForeignLocks(int $meetingId, int $userId): array
	{
		$now = $this->clock->now();
		$result = [];
		foreach ($this->locks->findByMeeting($this->meetings->get($meetingId)) as $lock) {
			if (!$lock->isExpired($now) && $lock->getUser()->getId() !== $userId) {
				$result[$lock->getAgendaItem()->getId()] = $lock;
			}
		}

		return $result;
	}

	/**
	 * Hlídá zápis: bod nesmí být zamčený někým jiným.
	 *
	 * @throws LockedException
	 */
	public function assertNotLockedByOther(int $itemId, int $userId): void
	{
		$lock = $this->locks->findByItem($this->items->get($itemId));
		if ($lock !== null && !$lock->isExpired($this->clock->now()) && $lock->getUser()->getId() !== $userId) {
			throw new LockedException($lock->getUser()->getName(), $lock->getExpiresAt());
		}
	}

	/**
	 * Úklid vypršelých zámků (příkaz locks:cleanup).
	 */
	public function cleanup(): int
	{
		return $this->transaction->run(fn(): int => $this->locks->removeExpired($this->clock->now()));
	}
}
