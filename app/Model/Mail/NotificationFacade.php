<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Project\ProjectRepository;
use App\Model\Shared\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Upozornění pro obecní úřad. Selhání odeslání se jen zaznamená – akce občana kvůli němu nesmí spadnout.
 */
final readonly class NotificationFacade
{
	public function __construct(
		private ProjectRepository $projects,
		private MailTemplates $templates,
		private Mailer $mailer,
		private MailLogRepository $logs,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * Nový podnět občana → e-mail na adresu obce z Nastavení.
	 *
	 * @return bool false = obec nemá e-mail nebo odeslání selhalo
	 */
	public function notifyNewProposal(int $projectId, string $adminUrl): bool
	{
		$project = $this->projects->get($projectId);
		$municipality = $project->getMunicipality();
		$recipient = $municipality->getProfile()->email;
		if ($recipient === null) {
			return false;
		}

		$message = new MailMessage(
			to: $recipient,
			toName: $municipality->getProfile()->officialName,
			subject: "Nový podnět občana: {$project->getTitle()}",
			html: $this->templates->render('proposal', $municipality, [
				'project' => $project,
				'url' => $adminUrl,
			]),
			fromName: 'Konšel',
			replyTo: $project->getSubmitterEmail(),
		);

		try {
			$this->mailer->send($message);
			$log = MailLog::sent($municipality, MailType::ProposalNotice, $message, $this->clock->now());
		} catch (MailException $e) {
			$log = MailLog::failed($municipality, MailType::ProposalNotice, $message, $e->getMessage(), $this->clock->now());
		}

		$this->transaction->run(fn() => $this->logs->add($log));

		return $log->getStatus() === MailStatus::Sent;
	}
}
