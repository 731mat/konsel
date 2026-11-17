<?php

declare(strict_types=1);

namespace App\Model\Attachment;

use App\Model\Comment\Comment;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Municipality\Municipality;
use App\Model\Project\Project;
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
 * Příloha: podklad k bodu programu, nebo soubor v komentáři diskuse. Soubor leží ve FileStorage,
 * servíruje se jen přes presenter s ACL.
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
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?AgendaItem $agendaItem;

	#[ORM\ManyToOne(targetEntity: Comment::class, inversedBy: 'attachments')]
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?Comment $comment;

	#[ORM\ManyToOne(targetEntity: Project::class)]
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?Project $project = null;

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

	private function __construct(Municipality $municipality, ?AgendaItem $agendaItem, ?Comment $comment, StoredFile $file, ?User $uploadedBy, bool $publicForMembers)
	{
		$this->municipality = $municipality;
		$this->agendaItem = $agendaItem;
		$this->comment = $comment;
		$this->file = $file;
		$this->uploadedBy = $uploadedBy;
		$this->publicForMembers = $publicForMembers;
		$this->title = self::normalizeTitle($file->originalName);
	}

	public static function forProject(Project $project, StoredFile $file, ?User $uploadedBy): self
	{
		$attachment = new self($project->getMunicipality(), null, null, $file, $uploadedBy, true);
		$attachment->project = $project;

		return $attachment;
	}

	/**
	 * @throws InvalidStateException zápis zasedání je ověřen
	 */
	public static function forAgendaItem(AgendaItem $item, StoredFile $file, ?User $uploadedBy, bool $publicForMembers = true): self
	{
		if ($item->getMeeting()->isArchived()) {
			throw new InvalidStateException('Zápis je ověřen, podklady zasedání už nelze měnit.');
		}

		return new self($item->getMunicipality(), $item, null, $file, $uploadedBy, $publicForMembers);
	}

	/**
	 * Soubor v komentáři – vidí ho každý, kdo vidí komentář.
	 *
	 * @internal použijte Comment::attach()
	 */
	public static function forComment(Comment $comment, StoredFile $file, ?User $uploadedBy): self
	{
		return new self($comment->getMunicipality(), null, $comment, $file, $uploadedBy, true);
	}

	public function rename(string $title): void
	{
		$this->assertChangeable();
		$this->title = self::normalizeTitle($title);
	}

	public function changeVisibility(bool $publicForMembers): void
	{
		$this->assertChangeable();
		$this->publicForMembers = $publicForMembers;
	}

	/**
	 * Soubory zasedání s ověřeným zápisem jsou součást archivu – nejdou přejmenovat, skrýt ani smazat.
	 *
	 * @throws InvalidStateException
	 */
	public function assertChangeable(): void
	{
		if ($this->getMeeting()?->isArchived() === true) {
			throw new InvalidStateException('Zápis je ověřen, podklady zasedání už nelze měnit.');
		}
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	/**
	 * Bod, ke kterému je příloha přímo (null u souboru v komentáři).
	 */
	public function getAgendaItem(): ?AgendaItem
	{
		return $this->agendaItem;
	}

	public function getComment(): ?Comment
	{
		return $this->comment;
	}

	/**
	 * Zasedání, ke kterému soubor patří (přímo přes bod, nebo přes komentář u bodu).
	 */
	public function getMeeting(): ?Meeting
	{
		return $this->agendaItem?->getMeeting() ?? $this->comment?->getAgendaItem()?->getMeeting();
	}

	/**
	 * Úkol, ke kterému soubor patří (přímo, nebo přes komentář v jeho diskusi).
	 */
	public function getProject(): ?Project
	{
		return $this->project ?? $this->comment?->getProject();
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

	private static function normalizeTitle(string $title): string
	{
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException('Název přílohy musí mít 1 až 255 znaků.', 'title');
		}

		return $title;
	}
}
