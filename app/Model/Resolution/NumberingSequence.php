<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Poslední přidělené číslo usnesení v řadě orgánu (rok / volební období / zasedání).
 * Mění se jen atomickým SQL v DoctrineNumberingSequenceRepository, ne přes entitu.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'numbering_sequence')]
#[ORM\UniqueConstraint(columns: ['body_id', 'scope_key'])]
final class NumberingSequence implements TenantOwned
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Body::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Body $body;

	#[ORM\Column(length: 50)]
	private string $scopeKey;

	#[ORM\Column]
	private int $lastValue;

	private function __construct(Body $body, string $scopeKey, int $lastValue)
	{
		$this->municipality = $body->getMunicipality();
		$this->body = $body;
		$this->scopeKey = $scopeKey;
		$this->lastValue = $lastValue;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getBody(): Body
	{
		return $this->body;
	}

	public function getScopeKey(): string
	{
		return $this->scopeKey;
	}

	public function getLastValue(): int
	{
		return $this->lastValue;
	}
}
