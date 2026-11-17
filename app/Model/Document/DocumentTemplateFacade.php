<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFilter;
use App\Model\Meeting\MeetingRepository;
use App\Model\Municipality\Municipality;
use App\Model\Municipality\MunicipalityRepository;
use App\Model\People\Body;
use App\Model\People\BodyRepository;
use App\Model\Resolution\Resolution;
use App\Model\Resolution\ResolutionRepository;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\User\UserRepository;
use Latte\CompileException;
use Latte\SecurityViolationException;

/**
 * Vlastní šablony dokumentů obce (HTML/Latte v sandboxu): úprava, kontrola, náhled a návrat k výchozí šabloně.
 */
final readonly class DocumentTemplateFacade
{
	public function __construct(
		private DocumentTemplateRepository $templates,
		private DocumentTemplateEngine $engine,
		private MeetingDocumentFacade $documents,
		private MunicipalityRepository $municipalities,
		private BodyRepository $bodies,
		private MeetingRepository $meetings,
		private ResolutionRepository $resolutions,
		private UserRepository $users,
		private TransactionManager $transaction,
	) {}

	/**
	 * @return list<DocumentTemplate> vlastní šablony obce
	 */
	public function findCustom(int $municipalityId): array
	{
		return $this->templates->findByMunicipality($this->municipalities->get($municipalityId));
	}

	public function findCustomFor(int $municipalityId, DocumentTemplateType $type, ?int $bodyId): ?DocumentTemplate
	{
		return $this->templates->find($this->municipalities->get($municipalityId), $this->body($bodyId), $type);
	}

	/**
	 * Zdroj šablony k úpravě: vlastní šablona (pro orgán, jinak pro obec), jinak výchozí.
	 */
	public function getSource(int $municipalityId, DocumentTemplateType $type, ?int $bodyId): string
	{
		$municipality = $this->municipalities->get($municipalityId);
		$template = ($bodyId !== null ? $this->templates->find($municipality, $this->body($bodyId), $type) : null)
			?? $this->templates->find($municipality, null, $type);

		return $template?->getSource() ?? $this->engine->getDefaultSource($type);
	}

	public function getDefaultSource(DocumentTemplateType $type): string
	{
		return $this->engine->getDefaultSource($type);
	}

	/**
	 * Uloží vlastní šablonu po kontrole: povinné náležitosti, zápis šablony, povolené konstrukce a zkušební vykreslení.
	 *
	 * @throws ValidationException
	 */
	public function save(int $municipalityId, DocumentTemplateType $type, ?int $bodyId, string $source, ?int $userId): void
	{
		$this->validate($municipalityId, $type, $bodyId, $source);
		$this->transaction->run(function () use ($municipalityId, $type, $bodyId, $source, $userId): void {
			$municipality = $this->municipalities->get($municipalityId);
			$body = $this->body($bodyId);
			$user = $userId === null ? null : $this->users->find($userId);
			$template = $this->templates->find($municipality, $body, $type);
			if ($template === null) {
				$this->templates->add(new DocumentTemplate($municipality, $body, $type, $source, $user));
			} else {
				$template->change($source, $user);
			}
		});
	}

	/**
	 * Smaže vlastní šablonu – dokument se zase tvoří z výchozí (případně z šablony celé obce).
	 */
	public function reset(int $municipalityId, DocumentTemplateType $type, ?int $bodyId): void
	{
		$this->transaction->run(function () use ($municipalityId, $type, $bodyId): void {
			$template = $this->templates->find($this->municipalities->get($municipalityId), $this->body($bodyId), $type);
			if ($template !== null) {
				$this->templates->remove($template);
			}
		});
	}

	/**
	 * Náhled neuložené šablony v PDF na skutečném zasedání obce.
	 *
	 * @throws ValidationException šablonu nejde vykreslit nebo obec nemá vhodné zasedání
	 */
	public function preview(int $municipalityId, DocumentTemplateType $type, ?int $bodyId, string $source): RenderedDocument
	{
		$overrides = [
			$type->fileName() => self::normalize($source),
		];
		try {
			return $this->renderSample($municipalityId, $type, $bodyId, $overrides, pdf: true)
				?? throw new ValidationException('Pro náhled chybí vhodné zasedání (u zápisu a usnesení zahájené, u výpisu zasedání s usnesením).', 'source');
		} catch (CompileException|SecurityViolationException $e) {
			throw self::templateError($e);
		}
	}

	/**
	 * @throws ValidationException
	 */
	private function validate(int $municipalityId, DocumentTemplateType $type, ?int $bodyId, string $source): void
	{
		$source = self::normalize($source);
		$missing = [];
		foreach ($type->requiredSnippets() as $snippet => $description) {
			if (!str_contains($source, $snippet)) {
				$missing[] = $description;
			}
		}

		if ($missing !== []) {
			throw new ValidationException('Šabloně chybí: ' . implode('; ', $missing) . '.', 'source');
		}

		$overrides = [
			$type->fileName() => $source,
		];
		try {
			$this->engine->compile($type, $overrides);
			$this->renderSample($municipalityId, $type, $bodyId, $overrides, pdf: false);
		} catch (CompileException|SecurityViolationException $e) {
			throw self::templateError($e);
		} catch (\Throwable $e) {
			throw new ValidationException("Šablonu se nepodařilo vykreslit na zkušebním zasedání: {$e->getMessage()}", 'source');
		}
	}

	/**
	 * Vykreslí dokument se šablonou na posledním vhodném zasedání (null = obec takové zasedání nemá).
	 *
	 * @param array<string, string> $overrides
	 */
	private function renderSample(int $municipalityId, DocumentTemplateType $type, ?int $bodyId, array $overrides, bool $pdf): ?RenderedDocument
	{
		$municipality = $this->municipalities->get($municipalityId);
		if ($type === DocumentTemplateType::ResolutionExtract) {
			$resolution = $this->sampleResolution($municipality, $this->body($bodyId));

			return $resolution === null ? null : $this->documents->generateResolutionExtract($resolution->getId(), editable: !$pdf, overrides: $overrides);
		}

		// hlavička platí pro všechny dokumenty – zkouší se na pozvánce
		$documentType = $type === DocumentTemplateType::Layout ? MeetingDocumentType::Invitation : MeetingDocumentType::from($type->value);
		$meeting = $this->sampleMeeting($municipality, $this->body($bodyId), $documentType);
		if ($meeting === null) {
			return null;
		}

		if (!$pdf) {
			return new RenderedDocument('sample.html', $this->documents->renderHtml($meeting, $documentType, overrides: $overrides), 'text/html');
		}

		return $this->documents->generate($meeting->getId(), $documentType, overrides: $overrides);
	}

	private function sampleMeeting(Municipality $municipality, ?Body $body, MeetingDocumentType $type): ?Meeting
	{
		foreach ($this->meetings->findByFilter($municipality, new MeetingFilter($body)) as $meeting) {
			if ($type->isAvailableFor($meeting->getStatus()) && $meeting->getAgendaItems() !== []) {
				return $meeting;
			}
		}

		return null;
	}

	private function sampleResolution(Municipality $municipality, ?Body $body): ?Resolution
	{
		foreach ($this->meetings->findByFilter($municipality, new MeetingFilter($body)) as $meeting) {
			$resolutions = $this->resolutions->findByMeeting($meeting);
			if ($resolutions !== []) {
				return $resolutions[0];
			}
		}

		return null;
	}

	private function body(?int $bodyId): ?Body
	{
		return $bodyId === null ? null : $this->bodies->get($bodyId);
	}

	private static function templateError(CompileException|SecurityViolationException $e): ValidationException
	{
		$line = $e->position?->line;
		$message = preg_replace('~\s*\(on line \d+ at column \d+\)$~', '', $e->getMessage()) ?? $e->getMessage();
		$message = strtr($message, [
			'is not allowed' => 'není v šablonách dokumentů povolené',
			'Unexpected' => 'Neočekávaný zápis',
		]);

		return new ValidationException(($line !== null ? "Chyba v šabloně na řádku {$line}: " : 'Chyba v šabloně: ') . $message, 'source');
	}

	private static function normalize(string $source): string
	{
		return str_replace(["\r\n", "\r"], "\n", $source);
	}
}
