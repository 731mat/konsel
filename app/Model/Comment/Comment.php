<?php

declare(strict_types=1);

namespace App\Model\Comment;

use App\Model\Attachment\Attachment;
use App\Model\Meeting\AgendaItem;
use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\User\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Komentář (připomínka) k bodu programu. Autorem je uživatel úřadu, nebo zastupitel (Person) z portálu.
 * Vlákna mají jednu úroveň: komentář → odpovědi. Smazání je měkké, aby vlákno dávalo smysl.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment')]
#[ORM\Index(columns: ['agenda_item_id', 'created_at'])]
final class Comment implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	public const int MaxLength = 5000;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: AgendaItem::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private AgendaItem $agendaItem;

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
	 * @throws ValidationException
	 * @throws InvalidStateException
	 */
	/**
	 * @param bool $withFiles komentář nese soubory – text pak není povinný
	 */
	public function __construct(AgendaItem $agendaItem, CommentAuthor $author, string $body, ?self $parent = null, bool $withFiles = false)
	{
		if (!$agendaItem->getMeeting()->canEditAttachments()) {
			throw new InvalidStateException('Zasedání je uzavřené, diskuse k bodům už není možná.');
		}

		if ($parent !== null && ($parent->agendaItem !== $agendaItem || $parent->parent !== null)) {
			throw new ValidationException('Odpovídat lze jen na komentář u téhož bodu.', 'parentId');
		}

		if ($author->municipality() !== $agendaItem->getMunicipality()) {
			throw new InvalidStateException('Autor komentáře patří k jiné obci.');
		}

		$this->municipality = $agendaItem->getMunicipality();
		$this->agendaItem = $agendaItem;
		$this->parent = $parent;
		$this->authorUser = $author->user;
		$this->authorPerson = $author->person;
		$this->authorName = $author->name();
		$this->body = self::normalize($body, $withFiles);
		$this->attachments = new ArrayCollection();
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
	public function delete(\DateTimeImmutable $at): void
	{
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

	public function getAgendaItem(): AgendaItem
	{
		return $this->agendaItem;
	}

	public function getParent(): ?self
	{
		return $this->parent;
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

	public function getBody(): string
	{
		return $this->deletedAt === null ? $this->body : '';
	}

	public function isDeleted(): bool
	{
		return $this->deletedAt !== null;
	}

	private static function normalize(string $body, bool $withFiles): string
	{
		$body = trim(str_replace(["\r\n", "\r"], "\n", $body));
		if ($body === '' && !$withFiles) {
			throw new ValidationException('Napište text komentáře.', 'body');
		}

		if (mb_strlen($body) > self::MaxLength) {
			throw new ValidationException('Komentář může mít nejvýše ' . self::MaxLength . ' znaků.', 'body');
		}

		return $body;
	}
}
