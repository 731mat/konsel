<?php

declare(strict_types=1);

namespace App\Model\Meeting;

interface AttendanceRepository
{
	/**
	 * @return list<Attendance>
	 */
	public function findByMeeting(Meeting $meeting): array;

	public function add(Attendance $attendance): void;
}
