<?php

declare(strict_types=1);

namespace App\Model\Comment;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Shared\Exception\NotFoundException;

interface CommentRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Comment;

	/**
	 * @return list<Comment> od nejstaršího
	 */
	public function findByAgendaItem(AgendaItem $item): array;

	/**
	 * @return array<int, int> ID bodu → počet nesmazaných komentářů
	 */
	public function countByMeeting(Meeting $meeting): array;

	public function add(Comment $comment): void;
}
