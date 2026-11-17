<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vlastní šablona dokumentu obce (HTML/Latte, vykresluje se v sandboxu). Bez orgánu platí pro všechny orgány,
 * s orgánem jen pro něj (např. zápis rady). Bez vlastní šablony se použije výchozí z resources/documents.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_template')]
#[ORM\UniqueConstraint(columns: ['municipality_id', 'body_id', 'type'])]
final class DocumentTemplate implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	public const int MaxLength = 200_000;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Body::class)]
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?Body $body;

	#[ORM\Column(length: 30, enumType: DocumentTemplateType::class)]
	private DocumentTemplateType $type;

	#[ORM\Column(type: 'text')]
	private string $source;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?User $updatedBy;

	public function __construct(Municipality $municipality, ?Body $body, DocumentTemplateType $type, string $source, ?User $updatedBy)
	{
		if ($body !== null && $body->getMunicipality() !== $municipality) {
			throw new ValidationException('Orgán patří k jiné obci.', 'body');
		}

		$this->municipality = $municipality;
		$this->body = $body;
		$this->type = $type;
		$this->change($source, $updatedBy);
	}

	/**
	 * @throws ValidationException
	 */
	public function change(string $source, ?User $updatedBy): void
	{
		$source = str_replace(["\r\n", "\r"], "\n", $source);
		if (trim($source) === '') {
			throw new ValidationException('Šablona je prázdná.', 'source');
		}

		if (strlen($source) > self::MaxLength) {
			throw new ValidationException('Šablona je příliš dlouhá (nejvýše 200 000 znaků).', 'source');
		}

		$this->source = $source;
		$this->updatedBy = $updatedBy;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getBody(): ?Body
	{
		return $this->body;
	}

	public function getType(): DocumentTemplateType
	{
		return $this->type;
	}

	public function getSource(): string
	{
		return $this->source;
	}

	public function getUpdatedBy(): ?User
	{
		return $this->updatedBy;
	}
}
