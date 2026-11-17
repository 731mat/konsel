<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Meeting\Meeting;
use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Záznam o odeslaném (nebo neodeslaném) e-mailu – kdo, kdy, co a s jakým výsledkem.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mail_log')]
#[ORM\Index(columns: ['meeting_id', 'sent_at'])]
final class MailLog implements TenantOwned
{
	use IntegerId;

	private const int MaxErrorLength = 1000;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\Column(length: 30, enumType: MailType::class)]
	private MailType $type;

	#[ORM\ManyToOne(targetEntity: Meeting::class)]
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?Meeting $meeting;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?Person $person;

	#[ORM\Column(length: 180)]
	private string $recipient;

	#[ORM\Column(length: 255)]
	private string $subject;

	#[ORM\Column(length: 20, enumType: MailStatus::class)]
	private MailStatus $status;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $error;

	#[ORM\Column]
	private \DateTimeImmutable $sentAt;

	private function __construct(Municipality $municipality, MailType $type, MailMessage $message, MailStatus $status, ?string $error, \DateTimeImmutable $at, ?Meeting $meeting, ?Person $person)
	{
		$this->municipality = $municipality;
		$this->type = $type;
		$this->meeting = $meeting;
		$this->person = $person;
		$this->recipient = $message->to->value;
		$this->subject = mb_substr($message->subject, 0, 255);
		$this->status = $status;
		$this->error = $error === null ? null : mb_substr($error, 0, self::MaxErrorLength);
		$this->sentAt = $at;
	}

	public static function sent(Municipality $municipality, MailType $type, MailMessage $message, \DateTimeImmutable $at, ?Meeting $meeting = null, ?Person $person = null): self
	{
		return new self($municipality, $type, $message, MailStatus::Sent, null, $at, $meeting, $person);
	}

	public static function failed(Municipality $municipality, MailType $type, MailMessage $message, string $error, \DateTimeImmutable $at, ?Meeting $meeting = null, ?Person $person = null): self
	{
		return new self($municipality, $type, $message, MailStatus::Failed, $error, $at, $meeting, $person);
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getType(): MailType
	{
		return $this->type;
	}

	public function getMeeting(): ?Meeting
	{
		return $this->meeting;
	}

	public function getPerson(): ?Person
	{
		return $this->person;
	}

	public function getRecipient(): string
	{
		return $this->recipient;
	}

	public function getSubject(): string
	{
		return $this->subject;
	}

	public function getStatus(): MailStatus
	{
		return $this->status;
	}

	public function getError(): ?string
	{
		return $this->error;
	}

	public function getSentAt(): \DateTimeImmutable
	{
		return $this->sentAt;
	}
}
