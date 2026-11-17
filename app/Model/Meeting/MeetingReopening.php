<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Záznam o znovuotevření zasedání: kdo, kdy, z jakého stavu a proč. Nemění se ani nemaže.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meeting_reopening')]
final class MeetingReopening implements TenantOwned
{
	use IntegerId;

	public const int MinReasonLength = 10;

	public const int MaxReasonLength = 1000;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Meeting::class, inversedBy: 'reopenings')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Meeting $meeting;

	#[ORM\Column(length: 20, enumType: MeetingStatus::class)]
	private MeetingStatus $fromStatus;

	#[ORM\Column(length: 20, enumType: MeetingStatus::class)]
	private MeetingStatus $toStatus;

	#[ORM\Column(type: 'text')]
	private string $reason;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?User $reopenedBy;

	/**
	 * Jméno zůstane v historii, i když se uživatel později smaže.
	 */
	#[ORM\Column(length: 200)]
	private string $reopenedByName;

	#[ORM\Column]
	private \DateTimeImmutable $reopenedAt;

	/**
	 * @internal vzniká jen přes Meeting::reopen()
	 * @throws ValidationException
	 */
	public function __construct(Meeting $meeting, MeetingStatus $fromStatus, MeetingStatus $toStatus, string $reason, ?User $reopenedBy, \DateTimeImmutable $reopenedAt)
	{
		$this->municipality = $meeting->getMunicipality();
		$this->meeting = $meeting;
		$this->fromStatus = $fromStatus;
		$this->toStatus = $toStatus;
		$this->reason = self::normalizeReason($reason);
		$this->reopenedBy = $reopenedBy;
		$this->reopenedByName = $reopenedBy?->getName() ?? 'systém';
		$this->reopenedAt = $reopenedAt;
	}

	/**
	 * @throws ValidationException
	 */
	public static function normalizeReason(string $reason): string
	{
		$reason = trim(str_replace(["\r\n", "\r"], "\n", $reason));
		$length = mb_strlen($reason);
		if ($length < self::MinReasonLength || $length > self::MaxReasonLength) {
			throw new ValidationException(sprintf('Uveďte důvod znovuotevření (%d až %d znaků) – zůstane v historii zasedání.', self::MinReasonLength, self::MaxReasonLength), 'reason');
		}

		return $reason;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getMeeting(): Meeting
	{
		return $this->meeting;
	}

	public function getFromStatus(): MeetingStatus
	{
		return $this->fromStatus;
	}

	public function getToStatus(): MeetingStatus
	{
		return $this->toStatus;
	}

	public function getReason(): string
	{
		return $this->reason;
	}

	public function getReopenedBy(): ?User
	{
		return $this->reopenedBy;
	}

	public function getReopenedByName(): string
	{
		return $this->reopenedByName;
	}

	public function getReopenedAt(): \DateTimeImmutable
	{
		return $this->reopenedAt;
	}
}
