<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Chráněné zdroje aplikace (ACL resources).
 */
enum Resource: string
{
	case Dashboard = 'dashboard';
	case Meeting = 'meeting';
	case Agenda = 'agenda';
	case Minutes = 'minutes';
	case Resolution = 'resolution';
	case Attachment = 'attachment';
	case Document = 'document';
	case Template = 'template';
	case Member = 'member';
	case User = 'user';
	case Settings = 'settings';
	case Audit = 'audit';

	/**
	 * Správa obcí napříč systémem – jen superadmin.
	 */
	case System = 'system';
}
