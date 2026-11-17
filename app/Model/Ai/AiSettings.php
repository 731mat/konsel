<?php

declare(strict_types=1);

namespace App\Model\Ai;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nastavení AI obce. API klíč je uložený jen zašifrovaný (šifruje AiFacade přes SecretBox).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_settings')]
#[ORM\UniqueConstraint(columns: ['municipality_id'])]
final class AiSettings implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	public const string DefaultModel = 'gpt-5-mini';

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $apiKeyEncrypted = null;

	/**
	 * Konec klíče pro zobrazení v UI (např. „…x9Qa“).
	 */
	#[ORM\Column(length: 20, nullable: true)]
	private ?string $apiKeyHint = null;

	#[ORM\Column(length: 100)]
	private string $model = self::DefaultModel;

	#[ORM\Column]
	private bool $enabled = false;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $verifiedAt = null;

	/**
	 * Pokyny obce pro AI – štábní kultura (styl, co ano a co ne).
	 */
	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $instructions = null;

	/**
	 * Vzorové texty obce (důvodové zprávy, usnesení), podle kterých AI píše.
	 */
	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $examples = null;

	/**
	 * Jako vzory poslat i poslední přijatá usnesení obce.
	 */
	#[ORM\Column]
	private bool $useRecentResolutions = true;

	public const int MaxInstructionsLength = 4000;

	public const int MaxExamplesLength = 20_000;

	public function __construct(Municipality $municipality)
	{
		$this->municipality = $municipality;
	}

	/**
	 * @throws ValidationException
	 */
	public function configure(string $model, bool $enabled): void
	{
		$model = trim($model);
		if (preg_match('~^[A-Za-z0-9._:-]{1,100}$~', $model) !== 1) {
			throw new ValidationException('Zadejte název modelu, např. ' . self::DefaultModel . '.', 'model');
		}

		if ($model !== $this->model) {
			$this->verifiedAt = null;
		}

		$this->model = $model;
		$this->enabled = $enabled;
	}

	/**
	 * @param string $encrypted výstup SecretBox::encrypt()
	 * @param string $plainKey jen pro odvození nápovědy (poslední 4 znaky), neukládá se
	 */
	public function changeApiKey(string $encrypted, string $plainKey): void
	{
		$this->apiKeyEncrypted = $encrypted;
		$this->apiKeyHint = '…' . substr($plainKey, -4);
		$this->verifiedAt = null;
	}

	public function removeApiKey(): void
	{
		$this->apiKeyEncrypted = null;
		$this->apiKeyHint = null;
		$this->verifiedAt = null;
	}

	/**
	 * @throws ValidationException
	 */
	public function changeWritingGuide(?string $instructions, ?string $examples, bool $useRecentResolutions): void
	{
		$instructions = self::normalize($instructions);
		$examples = self::normalize($examples);
		if ($instructions !== null && mb_strlen($instructions) > self::MaxInstructionsLength) {
			throw new ValidationException(sprintf('Pokyny mohou mít nejvýše %d znaků.', self::MaxInstructionsLength), 'instructions');
		}

		if ($examples !== null && mb_strlen($examples) > self::MaxExamplesLength) {
			throw new ValidationException(sprintf('Vzory mohou mít nejvýše %d znaků.', self::MaxExamplesLength), 'examples');
		}

		$this->instructions = $instructions;
		$this->examples = $examples;
		$this->useRecentResolutions = $useRecentResolutions;
	}

	public function getInstructions(): ?string
	{
		return $this->instructions;
	}

	public function getExamples(): ?string
	{
		return $this->examples;
	}

	public function usesRecentResolutions(): bool
	{
		return $this->useRecentResolutions;
	}

	private static function normalize(?string $text): ?string
	{
		$text = $text === null ? null : trim(str_replace(["\r\n", "\r"], "\n", $text));

		return $text === '' ? null : $text;
	}

	public function markVerified(\DateTimeImmutable $at): void
	{
		$this->verifiedAt = $at;
	}

	/**
	 * AI funkce se v UI nabízejí jen se zadaným klíčem a zapnutým přepínačem.
	 */
	public function isReady(): bool
	{
		return $this->enabled && $this->apiKeyEncrypted !== null;
	}

	public function hasApiKey(): bool
	{
		return $this->apiKeyEncrypted !== null;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getApiKeyEncrypted(): ?string
	{
		return $this->apiKeyEncrypted;
	}

	public function getApiKeyHint(): ?string
	{
		return $this->apiKeyHint;
	}

	public function getModel(): string
	{
		return $this->model;
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function getVerifiedAt(): ?\DateTimeImmutable
	{
		return $this->verifiedAt;
	}
}
