<?php

declare(strict_types=1);

namespace App\Model\Attachment;

use App\Model\Meeting\AgendaItem;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Příloha (podklad) k bodu programu. Soubor leží ve FileStorage, servíruje se jen přes presenter s ACL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attachment')]
final class Attachment implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: AgendaItem::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private AgendaItem $agendaItem;

	#[ORM\Column(type: 'stored_file')]
	private StoredFile $file;

	/**
	 * Název zobrazovaný v seznamu (výchozí = název souboru).
	 */
	#[ORM\Column(length: 255)]
	private string $title;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?User $uploadedBy;

	/**
	 * Příloha je vidět zastupitelům v portálu (T18). Neveřejné jsou jen pro úřad.
	 */
	#[ORM\Column]
	private bool $publicForMembers;

	public function __construct(AgendaItem $agendaItem, StoredFile $file, ?User $uploadedBy, bool $publicForMembers = true)
	{
		$this->municipality = $agendaItem->getMunicipality();
		$this->agendaItem = $agendaItem;
		$this->file = $file;
		$this->uploadedBy = $uploadedBy;
		$this->publicForMembers = $publicForMembers;
		$this->rename($file->originalName);
	}

	public function rename(string $title): void
	{
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException('Název přílohy musí mít 1 až 255 znaků.', 'title');
		}

		$this->title = $title;
	}

	public function changeVisibility(bool $publicForMembers): void
	{
		$this->publicForMembers = $publicForMembers;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getAgendaItem(): AgendaItem
	{
		return $this->agendaItem;
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

	public function isPublicForMembers(): bool
	{
		return $this->publicForMembers;
	}

	/**
	 * Prohlížeč umí soubor bezpečně zobrazit přímo (PDF, rastrový obrázek).
	 */
	public function canPreview(): bool
	{
		return $this->file->isPdf() || $this->file->isImage();
	}
}
