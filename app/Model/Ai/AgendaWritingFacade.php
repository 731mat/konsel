<?php

declare(strict_types=1);

namespace App\Model\Ai;

use App\Model\Meeting\MeetingRepository;
use App\Model\Resolution\ResolutionRepository;
use App\Model\Resolution\ResolutionSearch;
use App\Model\Resolution\ResolutionStatus;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Template\ResolutionTemplateRepository;
use App\Model\User\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * AI asistent textů bodu programu: návrh důvodové zprávy a návrhu usnesení podle pokynů a vzorů obce.
 * Návrh se jen vrátí k vložení do formuláře – nikdy se sám neuloží. Osobní údaje se neposílají
 * (předkladatel ani docházka), jen texty, které uživatel do formuláře napsal, a vzory obce.
 */
final readonly class AgendaWritingFacade
{
	public const int RecentResolutions = 20;

	public const int MaxExamplesChars = 12_000;

	public function __construct(
		private AiFacade $ai,
		private MeetingRepository $meetings,
		private ResolutionRepository $resolutions,
		private ResolutionTemplateRepository $resolutionTemplates,
		private AiUsageLogRepository $usage,
		private UserRepository $users,
		private TransactionManager $transaction,
		private ClockInterface $clock,
		private string $promptDir = __DIR__ . '/../../../resources/prompts',
	) {}

	/**
	 * @throws ValidationException chybí název bodu
	 * @throws \App\Model\Shared\Exception\InvalidStateException AI není zapnutá
	 * @throws AiException
	 */
	public function suggest(int $meetingId, AiPurpose $purpose, AgendaDraft $draft, ?int $userId): string
	{
		if (trim($draft->title) === '') {
			throw new ValidationException('Nejdřív napište název bodu – AI z něj vychází.', 'title');
		}

		$meeting = $this->meetings->get($meetingId);
		$completion = $this->ai->complete($meeting->getMunicipality()->getId(), $this->messages($meetingId, $purpose, $draft));

		$this->transaction->run(function () use ($meeting, $purpose, $completion, $userId): void {
			$this->usage->add(new AiUsageLog(
				$meeting->getMunicipality(),
				$userId === null ? null : $this->users->find($userId),
				$purpose,
				$completion,
				$this->clock->now(),
			));
		});

		return self::clean($completion->text);
	}

	/**
	 * Zprávy pro model (veřejné kvůli testům a ladění promptů).
	 *
	 * @return non-empty-list<AiMessage>
	 */
	public function messages(int $meetingId, AiPurpose $purpose, AgendaDraft $draft): array
	{
		$meeting = $this->meetings->get($meetingId);
		$municipality = $meeting->getMunicipality();
		$body = $meeting->getBody();
		$settings = $this->ai->getSettings($municipality->getId());

		$system = [
			$this->prompt('system'),
			str_replace('{orgán}', $body->getName(), $this->prompt($purpose->value)),
			"Obec: {$municipality->getProfile()->officialName}. Orgán: {$body->getName()}.",
		];

		$instructions = $settings?->getInstructions();
		if ($instructions !== null) {
			$system[] = "Pokyny obce (dodrž je přednostně):\n{$instructions}";
		}

		$examples = $this->examples($meetingId, $purpose, $settings);
		if ($examples !== '') {
			$system[] = "Vzory textů obce – napodob jejich styl a tvar, obsah nepřebírej:\n---\n{$examples}\n---";
		}

		$user = ["Název bodu: {$draft->title}"];
		$parent = $draft->parentId === null ? null : $meeting->findAgendaItem($draft->parentId);
		if ($parent !== null) {
			$user[] = "Bod je podbodem bodu: {$parent->getTitle()}";
		}

		if ($draft->description !== null && trim($draft->description) !== '') {
			$user[] = ($purpose === AiPurpose::AgendaDescription ? "Současný text důvodové zprávy:\n" : "Důvodová zpráva:\n") . trim($draft->description);
		}

		if ($purpose === AiPurpose::ProposedResolution && $draft->proposedResolution !== null && trim($draft->proposedResolution) !== '') {
			$user[] = "Současný návrh usnesení (vylepši ho):\n" . trim($draft->proposedResolution);
		}

		$user[] = "Navrhni {$purpose->label()}.";

		return [AiMessage::system(implode("\n\n", $system)), AiMessage::user(implode("\n\n", $user))];
	}

	private function examples(int $meetingId, AiPurpose $purpose, ?AiSettings $settings): string
	{
		$parts = [];
		$custom = $settings?->getExamples();
		if ($custom !== null) {
			$parts[] = $custom;
		}

		if ($purpose === AiPurpose::ProposedResolution) {
			$municipality = $this->meetings->get($meetingId)->getMunicipality();
			if ($settings === null || $settings->usesRecentResolutions()) {
				$recent = array_slice($this->resolutions->search($municipality, new ResolutionSearch(status: ResolutionStatus::Adopted)), 0, self::RecentResolutions);
				foreach ($recent as $resolution) {
					$parts[] = $resolution->getText();
				}
			}

			foreach (array_slice($this->resolutionTemplates->findByMunicipality($municipality), 0, 10) as $template) {
				$parts[] = $template->getText();
			}
		}

		return mb_substr(implode("\n\n", $parts), 0, self::MaxExamplesChars);
	}

	private function prompt(string $name): string
	{
		$content = @file_get_contents("{$this->promptDir}/{$name}.md"); // @ – chybějící soubor ohlásí výjimka

		return $content === false ? throw new \RuntimeException("Missing prompt '{$name}'.") : trim($content);
	}

	/**
	 * Odpověď bez Markdownu a zbytečných úvodů („Návrh usnesení:“).
	 */
	private static function clean(string $text): string
	{
		$text = trim((string) preg_replace('~^```[a-z]*\s*|\s*```$~i', '', trim($text)));
		$text = (string) preg_replace('~^(návrh usnesení|důvodová zpráva)\s*:\s*\n~iu', '', $text);

		return trim(str_replace('**', '', $text));
	}
}
