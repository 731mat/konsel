<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\Meeting;
use App\Model\Shared\Exception\NotFoundException;

interface ArchivedDocumentRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): ArchivedDocument;

	/**
	 * @return list<ArchivedDocument> od nejnovější
	 */
	public function findByMeeting(Meeting $meeting): array;

	/**
	 * @param list<Meeting> $meetings
	 * @return list<ArchivedDocument> od nejnovější
	 */
	public function findByMeetings(array $meetings): array;

	public function add(ArchivedDocument $document): void;

	public function remove(ArchivedDocument $document): void;
}
