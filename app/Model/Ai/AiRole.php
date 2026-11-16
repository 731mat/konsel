<?php

declare(strict_types=1);

namespace App\Model\Ai;

enum AiRole: string
{
	case System = 'system';
	case User = 'user';
	case Assistant = 'assistant';
}
