<?php

declare(strict_types=1);

namespace App\Model\Ai;

use App\Model\Municipality\Municipality;

interface AiUsageLogRepository
{
	public function add(AiUsageLog $log): void;

	/**
	 * @return array{count: int, tokens: int} použití od daného okamžiku
	 */
	public function summarize(Municipality $municipality, \DateTimeImmutable $since): array;
}
