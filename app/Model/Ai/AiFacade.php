<?php

declare(strict_types=1);

namespace App\Model\Ai;

use App\Core\Security\SecretBox;
use App\Model\Ai\Command\SaveAiSettings;
use App\Model\Municipality\MunicipalityRepository;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Nastavení AI obce (OpenAI) a jednotný vstup pro AI funkce.
 */
final readonly class AiFacade
{
	public function __construct(
		private AiSettingsRepository $settings,
		private MunicipalityRepository $municipalities,
		private AiClient $client,
		private SecretBox $secretBox,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	public function getSettings(int $municipalityId): ?AiSettings
	{
		return $this->settings->findByMunicipality($this->municipalities->get($municipalityId));
	}

	/**
	 * Obec má AI zapnutou a zadaný klíč – UI může nabízet AI funkce.
	 */
	public function isAvailable(int $municipalityId): bool
	{
		return $this->secretBox->isConfigured() && ($this->getSettings($municipalityId)?->isReady() ?? false);
	}

	public function isEncryptionConfigured(): bool
	{
		return $this->secretBox->isConfigured();
	}

	public function saveSettings(int $municipalityId, SaveAiSettings $command): void
	{
		$apiKey = $command->apiKey === null ? '' : trim($command->apiKey);
		if ($apiKey !== '') {
			self::assertApiKeyFormat($apiKey);
			if (!$this->secretBox->isConfigured()) {
				throw new ValidationException('Na serveru chybí šifrovací klíč aplikace, API klíč nelze bezpečně uložit. Kontaktujte správce systému.', 'apiKey');
			}
		}

		$this->transaction->run(function () use ($municipalityId, $command, $apiKey): void {
			$settings = $this->findOrCreate($municipalityId);
			$settings->configure($command->model, $command->enabled);
			if ($apiKey !== '') {
				$settings->changeApiKey($this->secretBox->encrypt($apiKey), $apiKey);
			}
		});
	}

	public function removeApiKey(int $municipalityId): void
	{
		$this->transaction->run(function () use ($municipalityId): void {
			$this->getSettings($municipalityId)?->removeApiKey();
		});
	}

	/**
	 * Ověří uložený klíč a model u OpenAI. Úspěch zaznamená.
	 *
	 * @throws InvalidStateException klíč není zadaný
	 * @throws AiException OpenAI klíč nebo model odmítlo, případně je nedostupné
	 */
	public function testConnection(int $municipalityId): void
	{
		$settings = $this->getSettings($municipalityId);
		$this->client->verify($this->decryptKey($settings), $settings?->getModel() ?? AiSettings::DefaultModel);

		$this->transaction->run(function () use ($municipalityId): void {
			$this->getSettings($municipalityId)?->markVerified($this->clock->now());
		});
	}

	/**
	 * @param non-empty-list<AiMessage> $messages
	 * @throws InvalidStateException AI není pro obec zapnutá
	 * @throws AiException
	 */
	public function complete(int $municipalityId, array $messages): AiCompletion
	{
		$settings = $this->getSettings($municipalityId);
		if ($settings === null || !$settings->isReady()) {
			throw new InvalidStateException('AI funkce nejsou zapnuté. Správce je zapne v Nastavení → Umělá inteligence.');
		}

		return $this->client->complete($this->decryptKey($settings), $settings->getModel(), $messages);
	}

	private function findOrCreate(int $municipalityId): AiSettings
	{
		$municipality = $this->municipalities->get($municipalityId);
		$settings = $this->settings->findByMunicipality($municipality);
		if ($settings === null) {
			$settings = new AiSettings($municipality);
			$this->settings->add($settings);
		}

		return $settings;
	}

	private function decryptKey(?AiSettings $settings): string
	{
		$encrypted = $settings?->getApiKeyEncrypted();
		if ($encrypted === null) {
			throw new InvalidStateException('Nejdřív uložte API klíč OpenAI.');
		}

		try {
			return $this->secretBox->decrypt($encrypted);
		} catch (\RuntimeException|\LogicException) {
			throw new InvalidStateException('Uložený API klíč nelze dešifrovat (změnil se šifrovací klíč aplikace?). Zadejte klíč znovu.');
		}
	}

	private static function assertApiKeyFormat(string $apiKey): void
	{
		if (!str_starts_with($apiKey, 'sk-') || preg_match('~^[A-Za-z0-9_\-]{20,300}$~', $apiKey) !== 1) {
			throw new ValidationException('API klíč OpenAI začíná „sk-“ a neobsahuje mezery. Zkopírujte ho celý z platform.openai.com.', 'apiKey');
		}
	}
}
