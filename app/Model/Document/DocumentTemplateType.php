<?php

declare(strict_types=1);

namespace App\Model\Document;

/**
 * Šablony dokumentů, které si obec může upravit. Hodnota = název souboru výchozí šablony v resources/documents.
 */
enum DocumentTemplateType: string
{
	case Layout = '@layout';
	case Invitation = 'invitation';
	case Materials = 'materials';
	case Minutes = 'minutes';
	case Resolutions = 'resolutions';
	case AttendanceSheet = 'attendance';
	case ResolutionExtract = 'resolution-extract';

	public static function fromMeetingDocument(MeetingDocumentType $type): self
	{
		return self::from($type->value);
	}

	public function fileName(): string
	{
		return $this->value . '.latte';
	}

	public function label(): string
	{
		return match ($this) {
			self::Layout => 'Společná hlavička a vzhled',
			self::ResolutionExtract => 'Výpis z usnesení',
			default => MeetingDocumentType::from($this->value)->label(),
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::Layout => 'Hlavička s logem a údaji obce, písmo a styly – platí pro všechny dokumenty. Obsah dokumentu se vkládá na místo {include content}.',
			self::ResolutionExtract => 'Výpis jednoho usnesení pro úřady, smluvní strany nebo dotace, s ověřením správnosti.',
			default => MeetingDocumentType::from($this->value)->description(),
		};
	}

	/**
	 * Co v šabloně nesmí chybět (zákonné náležitosti, vložení obsahu): kousek zdrojového kódu → popis pro chybovou hlášku.
	 *
	 * @return array<string, string>
	 */
	public function requiredSnippets(): array
	{
		return match ($this) {
			self::Layout => [
				'{include content}' => 'vložení obsahu dokumentu {include content}',
			],
			self::Invitation => [
				'getScheduledAt()' => 'datum a čas zasedání ($meeting->getScheduledAt())',
				'getPlace()' => 'místo konání ($meeting->getPlace())',
				'getAgendaItems()' => 'navržený program ($meeting->getAgendaItems()) – § 93 odst. 1',
			],
			self::Materials => [
				'getAgendaItems()' => 'body programu ($meeting->getAgendaItems())',
			],
			self::Minutes => [
				'$attendance' => 'počet přítomných ($attendance) – § 95 odst. 1',
				'getAgendaItems()' => 'schválený pořad jednání ($meeting->getAgendaItems())',
				'$rounds' => 'průběh a výsledek hlasování ($rounds)',
				'$resolutions' => 'přijatá usnesení ($resolutions)',
			],
			self::Resolutions => [
				'$resolutions' => 'přijatá usnesení ($resolutions)',
			],
			self::AttendanceSheet => [
				'$attendance' => 'seznam členů ($attendance)',
			],
			self::ResolutionExtract => [
				'$resolution' => 'znění usnesení ($resolution)',
			],
		};
	}

	/**
	 * Proměnné dostupné v šabloně (pro nápovědu v editoru).
	 *
	 * @return array<string, string>
	 */
	public function variables(): array
	{
		$common = [
			'$meeting' => 'zasedání: getTitle(), getScheduledAt(), getPlace(), getBody(), getAgendaItems(), getChairperson(), getRecorder(), getVerifiers(), getStartedAt(), getEndedAt()…',
			'$municipality' => 'obec: getName(), getIco()',
			'$profile' => 'údaje obce: officialName, getAddressLine(), email, phone, website',
			'$mayor' => 'starosta k datu zasedání (Person: getFullName()) nebo null',
			'$generatedAt' => 'datum vytvoření dokumentu',
			'$logoPath' => 'cesta k logu obce (pro <img src>) nebo null',
		];

		return $common + match ($this) {
			self::Layout, self::Invitation => [],
			self::AttendanceSheet => [
				'$attendance' => 'docházka: entries (person, function, attendance), quorum, withStatus(…)',
			],
			self::Materials => [
				'$attachments' => 'ID bodu → názvy příloh pro zastupitele',
			],
			self::Minutes => [
				'$attendance' => 'docházka: entries, quorum (present, members, required, isReached()), withStatus(AttendanceStatus::Present), withMovements()',
				'$rounds' => 'ID bodu → hlasování (VotingRound: getType(), count(VoteChoice::For), getResult(), votesWith(…))',
				'$resolutions' => 'ID hlasování → usnesení (getNumber(), getText())',
				'$namedVotes' => 'true = jmenovité hlasování',
				'$draft' => 'true = zasedání probíhá (koncept)',
			],
			self::Resolutions => [
				'$resolutions' => 'usnesení zasedání (getNumber(), getText(), getStatus(), getVotingRound())',
				'$deputy' => 'místostarosta nebo null',
				'$draft' => 'true = zasedání probíhá (koncept)',
			],
			self::ResolutionExtract => [
				'$resolution' => 'usnesení (getNumber(), getText(), getStatus(), getStatusNote(), getVotingRound())',
			],
		};
	}
}
