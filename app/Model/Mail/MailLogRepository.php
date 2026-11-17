<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Meeting\Meeting;

interface MailLogRepository
{
	public function add(MailLog $log): void;

	/**
	 * @return list<MailLog> nejnovější první
	 */
	public function findByMeeting(Meeting $meeting): array;
}
