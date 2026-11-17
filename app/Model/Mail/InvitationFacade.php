<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Access\AccessFacade;
use App\Model\Access\IssuedToken;
use App\Model\Document\MeetingDocumentFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingRepository;
use App\Model\Meeting\MeetingStatus;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Rozeslání pozvánek členům orgánu: osobní odkaz do portálu, termín, místo, program a pozvánka v PDF.
 */
final readonly class InvitationFacade
{
	public function __construct(
		private MeetingRepository $meetings,
		private AccessFacade $access,
		private MeetingDocumentFacade $documents,
		private MailTemplates $templates,
		private Mailer $mailer,
		private MailLogRepository $logs,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * Vydá nové osobní odkazy (dosavadní přestanou platit) a pošle je e-mailem.
	 *
	 * @param \Closure(string): string $portalUrl token → absolutní adresa vstupu do portálu
	 * @param ?int $personId jen jedné osobě (znovu poslat); null = všem členům orgánu k datu zasedání
	 * @throws InvalidStateException zasedání není sdílené
	 * @throws ValidationException orgán nemá členy, osoba z jiné obce
	 */
	public function send(int $meetingId, \Closure $portalUrl, ?int $personId = null): InvitationResult
	{
		$meeting = $this->meetings->get($meetingId);
		if ($meeting->getStatus() !== MeetingStatus::Shared) {
			throw new InvalidStateException('Pozvánky lze rozeslat u sdíleného zasedání, které ještě nezačalo. Nejdřív zasedání sdílejte.');
		}

		$issued = $personId === null
			? $this->access->issueForMembers($meetingId)
			: [$this->access->issueFor($meetingId, $personId)];
		$pdf = $this->documents->generate($meetingId, MeetingDocumentType::Invitation);
		$attachment = new MailAttachment($pdf->fileName, $pdf->content, $pdf->mimeType);

		$sent = $withoutEmail = $failed = $logs = [];
		foreach ($issued as $token) {
			$person = $token->accessToken->getPerson();
			$email = $person->getEmail();
			if ($email === null) {
				$withoutEmail[] = $token;
				continue;
			}

			$message = $this->message($meeting, $token, $portalUrl($token->token), $attachment);
			try {
				$this->mailer->send($message);
				$logs[] = MailLog::sent($meeting->getMunicipality(), MailType::Invitation, $message, $this->clock->now(), $meeting, $person);
				$sent[] = $token;
			} catch (MailException $e) {
				$logs[] = MailLog::failed($meeting->getMunicipality(), MailType::Invitation, $message, $e->getMessage(), $this->clock->now(), $meeting, $person);
				$failed[] = $token;
			}
		}

		$this->transaction->run(function () use ($logs): void {
			array_walk($logs, fn(MailLog $log) => $this->logs->add($log));
		});

		return new InvitationResult($sent, $withoutEmail, $failed);
	}

	/**
	 * @return list<MailLog>
	 */
	public function findLog(int $meetingId): array
	{
		return $this->logs->findByMeeting($this->meetings->get($meetingId));
	}

	private function message(Meeting $meeting, IssuedToken $token, string $url, MailAttachment $attachment): MailMessage
	{
		$person = $token->accessToken->getPerson();
		$municipality = $meeting->getMunicipality();
		$email = $person->getEmail() ?? throw new \LogicException('Person without e-mail.');
		$subject = sprintf('Pozvánka: %s – %s, %s', $meeting->getTitle(), $meeting->getBody()->getName(), $meeting->getScheduledAt()->format('j. n. Y'));

		return new MailMessage(
			to: $email,
			toName: $person->getFullName(),
			subject: $subject,
			html: $this->templates->render('invitation', $municipality, [
				'meeting' => $meeting,
				'person' => $person,
				'url' => $url,
				'expiresAt' => $token->accessToken->getExpiresAt(),
				'items' => $meeting->getAgendaItems(),
			]),
			fromName: $municipality->getProfile()->officialName,
			replyTo: $municipality->getProfile()->email,
			attachments: [$attachment],
		);
	}
}
