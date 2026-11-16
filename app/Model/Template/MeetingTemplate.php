<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Šablona programu zasedání (seznam bodů s texty a návrhy usnesení, které mohou obsahovat proměnné).
 */
#[ORM\Entity]
#[ORM\Table(name: 'meeting_template')]
final class MeetingTemplate implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\Column(length: 200)]
	private string $name;

	#[ORM\Column(length: 20, enumType: MeetingType::class)]
	private MeetingType $meetingType;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $note;

	/**
	 * @var list<array{title: string, description: ?string, proposedResolution: ?string}>
	 */
	#[ORM\Column(type: 'json')]
	private array $items;

	/**
	 * @param non-empty-list<MeetingTemplateItem> $items
	 */
	public function __construct(Municipality $municipality, string $name, MeetingType $meetingType, array $items, ?string $note = null)
	{
		$this->municipality = $municipality;
		$this->meetingType = $meetingType;
		$this->note = $note;
		$this->rename($name);
		$this->items = array_map(static fn(MeetingTemplateItem $item): array => $item->toArray(), $items);
	}

	public function rename(string $name): void
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 200) {
			throw new ValidationException('Název šablony musí mít 1 až 200 znaků.', 'name');
		}

		$this->name = $name;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getMeetingType(): MeetingType
	{
		return $this->meetingType;
	}

	public function getNote(): ?string
	{
		return $this->note;
	}

	/**
	 * @return list<MeetingTemplateItem>
	 */
	public function getItems(): array
	{
		return array_map(MeetingTemplateItem::fromArray(...), $this->items);
	}
}
