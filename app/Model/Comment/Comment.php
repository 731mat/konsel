<?php

declare(strict_types=1);

namespace App\Model\Comment;

use App\Model\Attachment\Attachment;
use App\Model\Meeting\AgendaItem;
use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Project\Project;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\RichText\RichText;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\User\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Komentář k bodu programu nebo k úkolu v úkolníčku. Autorem je uživatel úřadu, nebo zastupitel (Person) z portálu.
 * Vlákna mají jednu úroveň: komentář → odpovědi. Smazání je měkké, aby vlákno dávalo smysl.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment')]
#[ORM\Index(columns: ['agenda_item_id', 'created_at'])]
final class Comment implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	/**
	 * Nejvýše znaků textu (bez formátování).
	 */
	public const int MaxLength = 5000;

	/**
	 * Nejvýše znaků HTML včetně značek (ochrana před zneužitím formátování).
	 */
	public const int MaxHtmlLength = 50_000;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: AgendaItem::class)]
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?AgendaItem $agendaItem;

	#[ORM\ManyToOne(targetEntity: Project::class)]
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?Project $project;

	/**
	 * Komentář k úkolu je vidět i občanům na veřejné stránce (o zveřejnění rozhoduje úřad).
	 */
	#[ORM\Column]
	private bool $publicForCitizens = false;

	#[ORM\ManyToOne(targetEntity: self::class)]
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?self $parent;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?User $authorUser;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?Person $authorPerson;

	/**
	 * Jméno autora v okamžiku napsání (zůstane i po smazání účtu).
	 */
	#[ORM\Column(length: 200)]
	private string $authorName;

	/**
	 * Pročištěné HTML (RichText).
	 */
	#[ORM\Column(type: 'text')]
	private string $body;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $deletedAt = null;

	/**
	 * @var Collection<int, Attachment>
	 */
	#[ORM\OneToMany(targetEntity: Attachment::class, mappedBy: 'comment', cascade: ['persist'])]
	#[ORM\OrderBy([
		'id' => 'ASC',
	])]
	private Collection $attachments;

	/**
	 * @param bool $withFiles komentář nese soubory – text pak není povinný
	 * @throws ValidationException
	 * @throws InvalidStateException
	 */
	private function __construct(Municipality $municipality, ?AgendaItem $agendaItem, ?Project $project, CommentAuthor $author, RichText $body, ?self $parent, bool $withFiles)
	{
		$body = self::validate($body, $withFiles);
		if ($parent !== null && ($parent->agendaItem !== $agendaItem || $parent->project !== $project || $parent->parent !== null)) {
			throw new ValidationException('Odpovídat lze jen na komentář ve stejné diskusi.', 'parentId');
		}

		if ($author->municipality() !== $municipality) {
			throw new InvalidStateException('Autor komentáře patří k jiné obci.');
		}

		$this->municipality = $municipality;
		$this->agendaItem = $agendaItem;
		$this->project = $project;
		$this->parent = $parent;
		$this->authorUser = $author->user;
		$this->authorPerson = $author->person;
		$this->authorName = $author->name();
		$this->body = $body->html;
		$this->attachments = new ArrayCollection();
	}

	/**
	 * @throws ValidationException
	 * @throws InvalidStateException zasedání je uzavřené
	 */
	public static function onAgendaItem(AgendaItem $item, CommentAuthor $author, RichText $body, ?self $parent = null, bool $withFiles = false): self
	{
		if (!$item->getMeeting()->canEditAttachments()) {
			throw new InvalidStateException('Zasedání je uzavřené, diskuse k bodům už není možná.');
		}

		return new self($item->getMunicipality(), $item, null, $author, $body, $parent, $withFiles);
	}

	/**
	 * @throws ValidationException
	 */
	public static function onProject(Project $project, CommentAuthor $author, RichText $body, ?self $parent = null, bool $withFiles = false): self
	{
		return new self($project->getMunicipality(), null, $project, $author, $body, $parent, $withFiles);
	}

	public function changeVisibility(bool $publicForCitizens): void
	{
		if ($this->project === null && $publicForCitizens) {
			throw new InvalidStateException('Občanům lze zveřejnit jen komentáře v úkolníčku.');
		}

		$this->publicForCitizens = $publicForCitizens;
	}

	public function attach(StoredFile $file, ?User $uploadedBy): Attachment
	{
		$attachment = Attachment::forComment($this, $file, $uploadedBy);
		$this->attachments->add($attachment);

		return $attachment;
	}

	/**
	 * @return list<Attachment>
	 */
	public function getAttachments(): array
	{
		return $this->deletedAt === null ? array_values($this->attachments->toArray()) : [];
	}

	/**
	 * @return list<Attachment> včetně skrytých u smazaného komentáře (pro úklid souborů)
	 */
	public function getAllAttachments(): array
	{
		return array_values($this->attachments->toArray());
	}

	/**
	 * Smazat smí autor; správce (rozhoduje volající podle oprávnění) i cizí komentář.
	 */
	/**
	 * @throws InvalidStateException diskuse u bodu zasedání s ověřeným zápisem je součást archivu
	 */
	public function delete(\DateTimeImmutable $at): void
	{
		if ($this->agendaItem?->getMeeting()->isArchived() === true) {
			throw new InvalidStateException('Zápis je ověřen, diskusi k bodům už nelze měnit.');
		}

		$this->deletedAt ??= $at;
	}

	public function isWrittenBy(CommentAuthor $author): bool
	{
		return ($author->user !== null && $author->user === $this->authorUser)
			|| ($author->person !== null && $author->person === $this->authorPerson);
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getAgendaItem(): ?AgendaItem
	{
		return $this->agendaItem;
	}

	public function getProject(): ?Project
	{
		return $this->project;
	}

	public function isPublicForCitizens(): bool
	{
		return $this->publicForCitizens;
	}

	public function getParent(): ?self
	{
		return $this->parent;
	}

	public function getAuthorUser(): ?User
	{
		return $this->authorUser;
	}

	public function getAuthorUserId(): ?int
	{
		return $this->authorUser?->getId();
	}

	public function getAuthorPersonId(): ?int
	{
		return $this->authorPerson?->getId();
	}

	public function getAuthorName(): string
	{
		return $this->authorName;
	}

	/**
	 * Komentář napsal zastupitel (z portálu), ne úřad.
	 */
	public function isFromMember(): bool
	{
		return $this->authorPerson !== null;
	}

	public function getBody(): RichText
	{
		return $this->deletedAt === null ? RichText::fromSanitizedHtml($this->body) : RichText::empty();
	}

	public function isDeleted(): bool
	{
		return $this->deletedAt !== null;
	}

	private static function validate(RichText $body, bool $withFiles): RichText
	{
		if ($body->isEmpty()) {
			if (!$withFiles) {
				throw new ValidationException('Napište text komentáře.', 'body');
			}

			return RichText::empty();
		}

		if ($body->length() > self::MaxLength) {
			throw new ValidationException('Komentář může mít nejvýše ' . self::MaxLength . ' znaků.', 'body');
		}

		if (strlen($body->html) > self::MaxHtmlLength) {
			throw new ValidationException('Komentář obsahuje příliš mnoho formátování. Zkraťte ho nebo formátování zjednodušte.', 'body');
		}

		return $body;
	}
}
