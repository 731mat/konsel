<?php

declare(strict_types=1);

namespace App\Model\Backup;

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Plán pravidelných záloh celé instalace (jeden řádek). Spouští ho systémový cron každou hodinu
 * příkazem backup:run --scheduled – záloha proběhne, jen když je podle plánu na řadě.
 */
#[ORM\Entity]
#[ORM\Table(name: 'backup_settings')]
final class BackupSettings
{
	use IntegerId;

	public const int MaxKeep = 100;

	#[ORM\Column]
	private bool $enabled = false;

	#[ORM\Column(length: 20, enumType: BackupFrequency::class)]
	private BackupFrequency $frequency = BackupFrequency::Daily;

	/**
	 * Hodina spuštění (0–23, čas obce).
	 */
	#[ORM\Column]
	private int $hour = 3;

	/**
	 * Den v týdnu u týdenní zálohy (1 = pondělí … 7 = neděle).
	 */
	#[ORM\Column]
	private int $weekday = 1;

	/**
	 * Den v měsíci u měsíční zálohy (1–28, aby existoval v každém měsíci).
	 */
	#[ORM\Column]
	private int $monthDay = 1;

	#[ORM\Column(length: 20, enumType: BackupContent::class)]
	private BackupContent $content = BackupContent::Full;

	/**
	 * Kolik posledních záloh držet (starší se smažou).
	 */
	#[ORM\Column]
	private int $keep = 7;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $lastRunAt = null;

	#[ORM\Column(nullable: true)]
	private ?bool $lastSucceeded = null;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $lastMessage = null;

	/**
	 * @throws ValidationException
	 */
	public function configure(bool $enabled, BackupFrequency $frequency, int $hour, int $weekday, int $monthDay, BackupContent $content, int $keep): void
	{
		if ($hour < 0 || $hour > 23) {
			throw new ValidationException('Hodina musí být 0 až 23.', 'hour');
		}

		if ($weekday < 1 || $weekday > 7) {
			throw new ValidationException('Vyberte den v týdnu.', 'weekday');
		}

		if ($monthDay < 1 || $monthDay > 28) {
			throw new ValidationException('Den v měsíci musí být 1 až 28 (aby existoval v každém měsíci).', 'monthDay');
		}

		if ($keep < 1 || $keep > self::MaxKeep) {
			throw new ValidationException(sprintf('Držet lze 1 až %d záloh.', self::MaxKeep), 'keep');
		}

		$this->enabled = $enabled;
		$this->frequency = $frequency;
		$this->hour = $hour;
		$this->weekday = $weekday;
		$this->monthDay = $monthDay;
		$this->content = $content;
		$this->keep = $keep;
	}

	public function recordRun(\DateTimeImmutable $at, bool $succeeded, ?string $message): void
	{
		$this->lastRunAt = $at;
		$this->lastSucceeded = $succeeded;
		$this->lastMessage = $message === null ? null : mb_substr($message, 0, 2000);
	}

	/**
	 * Poslední termín podle plánu, který už nastal (≤ $now).
	 */
	public function lastSlot(\DateTimeImmutable $now): \DateTimeImmutable
	{
		$slot = match ($this->frequency) {
			BackupFrequency::Daily => $now->setTime($this->hour, 0),
			BackupFrequency::Weekly => $now->modify('monday this week')->modify('+' . ($this->weekday - 1) . ' days')->setTime($this->hour, 0),
			BackupFrequency::Monthly => $now->setDate((int) $now->format('Y'), (int) $now->format('n'), $this->monthDay)->setTime($this->hour, 0),
		};

		if ($slot <= $now) {
			return $slot;
		}

		return match ($this->frequency) {
			BackupFrequency::Daily => $slot->modify('-1 day'),
			BackupFrequency::Weekly => $slot->modify('-1 week'),
			BackupFrequency::Monthly => $slot->modify('-1 month'),
		};
	}

	/**
	 * Nejbližší další termín podle plánu (> $now).
	 */
	public function nextSlot(\DateTimeImmutable $now): \DateTimeImmutable
	{
		return match ($this->frequency) {
			BackupFrequency::Daily => $this->lastSlot($now)->modify('+1 day'),
			BackupFrequency::Weekly => $this->lastSlot($now)->modify('+1 week'),
			BackupFrequency::Monthly => $this->lastSlot($now)->modify('+1 month'),
		};
	}

	/**
	 * Záloha je na řadě: plán je zapnutý a od posledního termínu ještě neproběhla.
	 */
	public function isDue(\DateTimeImmutable $now): bool
	{
		return $this->enabled && ($this->lastRunAt === null || $this->lastRunAt < $this->lastSlot($now));
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function getFrequency(): BackupFrequency
	{
		return $this->frequency;
	}

	public function getHour(): int
	{
		return $this->hour;
	}

	public function getWeekday(): int
	{
		return $this->weekday;
	}

	public function getMonthDay(): int
	{
		return $this->monthDay;
	}

	public function getContent(): BackupContent
	{
		return $this->content;
	}

	public function getKeep(): int
	{
		return $this->keep;
	}

	public function getLastRunAt(): ?\DateTimeImmutable
	{
		return $this->lastRunAt;
	}

	public function getLastSucceeded(): ?bool
	{
		return $this->lastSucceeded;
	}

	public function getLastMessage(): ?string
	{
		return $this->lastMessage;
	}
}
