<?php

declare(strict_types=1);

namespace App\Model\Recording;

use App\Model\Meeting\Meeting;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Zvukový záznam zasedání (většinou MP3, může jich být víc – např. po přestávce). Přepis doplní T40.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recording')]
final class Recording implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Meeting::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Meeting $meeting;

	#[ORM\Column(type: 'stored_file')]
	private StoredFile $file;

	#[ORM\Column(length: 255)]
	private string $title;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?User $uploadedBy;

	public function __construct(Meeting $meeting, StoredFile $file, ?User $uploadedBy)
	{
		$this->municipality = $meeting->getMunicipality();
		$this->meeting = $meeting;
		$this->file = $file;
		$this->uploadedBy = $uploadedBy;
		$title = trim(pathinfo($file->originalName, PATHINFO_FILENAME));
		$this->title = mb_substr($title === '' ? 'Záznam' : $title, 0, 255);
	}

	/**
	 * Záznam zasedání s ověřeným zápisem patří do archivu – smazat ho nejde (doplnit další ano).
	 *
	 * @throws InvalidStateException
	 */
	public function assertDeletable(): void
	{
		if ($this->meeting->isArchived()) {
			throw new InvalidStateException('Zápis je ověřen, záznam zasedání už nelze smazat.');
		}
	}

	/**
	 * @throws ValidationException
	 */
	public function rename(string $title): void
	{
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException('Název záznamu musí mít 1 až 255 znaků.', 'title');
		}

		$this->title = $title;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getMeeting(): Meeting
	{
		return $this->meeting;
	}

	public function getFile(): StoredFile
	{
		return $this->file;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getUploadedBy(): ?User
	{
		return $this->uploadedBy;
	}
}
