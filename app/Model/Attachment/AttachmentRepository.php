<?php

declare(strict_types=1);

namespace App\Model\Attachment;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Shared\Exception\NotFoundException;

interface AttachmentRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Attachment;

	/**
	 * @return list<Attachment> v pořadí nahrání
	 */
	public function findByAgendaItem(AgendaItem $item): array;

	/**
	 * @return list<Attachment>
	 */
	public function findByMeeting(Meeting $meeting): array;

	/**
	 * @return array<int, int> ID bodu → počet příloh
	 */
	public function countByMeeting(Meeting $meeting): array;

	/**
	 * Všechny soubory zasedání včetně souborů v komentářích (pro úklid při mazání).
	 *
	 * @return list<Attachment>
	 */
	public function findAllForMeeting(Meeting $meeting): array;

	/**
	 * Všechny soubory bodu včetně souborů v komentářích (pro úklid při mazání).
	 *
	 * @return list<Attachment>
	 */
	public function findAllForAgendaItem(AgendaItem $item): array;

	public function add(Attachment $attachment): void;

	public function remove(Attachment $attachment): void;
}
