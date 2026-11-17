<?php

declare(strict_types=1);

namespace App\AdminModule\Meeting;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Access\AccessFacade;
use App\Model\Access\IssuedToken;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Comment\CommentFacade;
use App\Model\Document\DocumentArchiveFacade;
use App\Model\Document\MeetingDocumentFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Mail\InvitationFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\AssignOfficials;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingReopening;
use App\Model\Meeting\MeetingStatus;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\MeetingType;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\People\Body;
use App\Model\People\PeopleFacade;
use App\Model\Resolution\ResolutionFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Template\TemplateFacade;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use App\UI\Form\FormUploads;
use Nette\Application\Attributes\Requires;
use Nette\Application\Responses\CallbackResponse;
use Nette\Application\UI\Form;
use Psr\Clock\ClockInterface;

/**
 * Zasedání: seznam, založení, detail se záložkami a přechody životního cyklu.
 */
#[Allowed(Resource::Meeting)]
final class MeetingPresenter extends SecuredPresenter
{
	/**
	 * Záložky detailu zasedání (obsah doplní další tasky).
	 */
	public const array Tabs = [
		'program' => 'Program',
		'proceedings' => 'Průběh',
		'resolutions' => 'Usnesení',
		'documents' => 'Dokumenty',
	];

	private ?Meeting $meeting = null;

	/**
	 * Právě vydané odkazy – zobrazí se jen v odpovědi na jejich vytvoření.
	 *
	 * @var list<IssuedToken>
	 */
	private array $issuedTokens = [];

	/**
	 * Proč se odkazy ukazují: 'created' (jen vytvořené), 'undelivered' (e-mail neodešel).
	 */
	private string $issuedReason = 'created';

	public function __construct(
		private readonly MeetingFacade $meetingFacade,
		private readonly AgendaFacade $agendaFacade,
		private readonly AttachmentFacade $attachmentFacade,
		private readonly CommentFacade $commentFacade,
		private readonly MeetingDocumentFacade $documentFacade,
		private readonly DocumentArchiveFacade $archiveFacade,
		private readonly AccessFacade $accessFacade,
		private readonly InvitationFacade $invitationFacade,
		private readonly ProceedingsFacade $proceedingsFacade,
		private readonly ResolutionFacade $resolutionFacade,
		private readonly TemplateFacade $templateFacade,
		private readonly PeopleFacade $peopleFacade,
		private readonly FormFactory $formFactory,
		private readonly ClockInterface $clock,
	) {
		parent::__construct();
	}

	/**
	 * Parametry filtru přicházejí z GET formuláře jako řetězce (prázdný = bez omezení).
	 */
	public function renderDefault(?string $body = null, ?string $year = null, ?string $status = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		// bez parametru platí orgán z přepínače v horní liště, prázdný parametr = všechny orgány
		$body ??= (string) $this->selectedBodyId();
		$selectedBody = ctype_digit($body) ? $this->loadBody((int) $body) : null;
		$year = ctype_digit((string) $year) ? (int) $year : null;
		$selectedStatus = MeetingStatus::tryFrom((string) $status);

		$this->template->meetings = $this->meetingFacade->find($municipalityId, $selectedBody?->getId(), $year, $selectedStatus);
		$this->template->bodies = $this->findBodies();
		$this->template->years = $this->meetingFacade->findYears($municipalityId);
		$this->template->statuses = MeetingStatus::cases();
		$this->template->selectedBody = $selectedBody;
		$this->template->selectedYear = $year;
		$this->template->selectedStatus = $selectedStatus;
		$this->template->canEdit = $this->isAllowed(Resource::Meeting, Privilege::Edit);
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	public function actionAdd(?int $body = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$form = $this->getComponent('meetingForm');
		$form->setDefaults([
			'place' => $this->meetingFacade->suggestPlace($municipalityId),
		]);
		if ($body !== null) {
			$form->setDefaults([
				'body' => $this->loadBody($body)->getId(),
			]);
		}
	}

	public function renderAdd(): void
	{
		$this->template->hasBodies = $this->findBodies() !== [];
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	public function actionEdit(int $id): void
	{
		$this->meeting = $this->loadMeeting($id);
		if (!$this->meeting->canReschedule()) {
			$this->flashMessage('Datum, místo a typ lze měnit jen před zahájením zasedání.', 'warning');
			$this->redirect('detail', [
				'id' => $id,
			]);
		}

		$this->getComponent('meetingForm')->setDefaults([
			'type' => $this->meeting->getType()->value,
			'number' => $this->meeting->getNumber(),
			'scheduledAt' => $this->meeting->getScheduledAt(),
			'place' => $this->meeting->getPlace(),
		]);
	}

	public function renderEdit(): void
	{
		$this->template->meeting = $this->meeting;
	}

	public function actionDetail(int $id): void
	{
		$this->meeting = $this->loadMeeting($id);
	}

	public function renderDetail(string $tab = 'program'): void
	{
		$meeting = $this->meeting ?? $this->error();
		$canEdit = $this->isAllowed(Resource::Meeting, Privilege::Edit);
		$canManage = $this->isAllowed(Resource::Meeting, Privilege::Manage);

		$this->template->meeting = $meeting;
		$this->template->tab = array_key_exists($tab, self::Tabs) ? $tab : 'program';
		$this->template->tabs = self::Tabs;
		$this->template->transitions = array_values(array_filter(
			$meeting->getAvailableTransitions(),
			static fn(MeetingTransition $t): bool => $t->isReopening() ? $canManage : $canEdit,
		));
		$this->template->warnings = $warnings = $this->meetingFacade->getWarnings($meeting);
		$this->template->confirmations = array_filter([
			MeetingTransition::Share->value => $warnings === [] ? null : implode(' ', $warnings) . ' Přesto sdílet?',
			MeetingTransition::Close->value => 'Ukončit zasedání?',
			MeetingTransition::ApproveMinutes->value => 'Po ověření zápisu už nepůjde měnit průběh zasedání ani funkce. Pokračovat?',
		]);
		$this->template->canEdit = $canEdit;
		$this->template->attachmentCounts = $this->attachmentFacade->countByMeeting($meeting);
		$this->template->commentCounts = $this->commentFacade->countByMeeting($meeting);
		$this->template->documentTypes = array_values(array_filter(
			MeetingDocumentType::cases(),
			static fn(MeetingDocumentType $type): bool => $type->isAvailableFor($meeting->getStatus()),
		));
		$this->template->meetingTemplates = $this->templateFacade->findMeetingTemplates($meeting->getMunicipality()->getId());
		$this->template->canSaveTemplate = $this->isAllowed(Resource::Template, Privilege::Edit) && $meeting->getAgendaItems() !== [];
		$this->template->canViewDocuments = $canViewDocuments = $this->isAllowed(Resource::Document);
		$documentsTab = $this->template->tab === 'documents' && $canViewDocuments;
		$this->template->archivedDocuments = $documentsTab ? $this->archiveFacade->findByMeeting($meeting->getId()) : [];
		$this->template->canArchive = $this->isAllowed(Resource::Document, Privilege::Edit);
		$this->template->canDeleteArchived = $this->isAllowed(Resource::Document, Privilege::Delete) && $meeting->getStatus() !== MeetingStatus::MinutesApproved;
		$this->template->missingFinal = $documentsTab && $meeting->getStatus() === MeetingStatus::MinutesApproved && !$this->archiveFacade->hasFinalVersion($meeting->getId());
		$this->template->attendance = $this->template->tab === 'proceedings' ? $this->proceedingsFacade->attendance($meeting->getId()) : null;
		$this->template->resolutions = $this->template->tab === 'resolutions' ? $this->resolutionFacade->findByMeeting($meeting->getId()) : [];
		$this->template->canEditProgram = $meeting->canEditProgram() && $this->isAllowed(Resource::Agenda, Privilege::Edit);
		$this->template->canDelete = $this->isAllowed(Resource::Meeting, Privilege::Delete) && $meeting->getStatus() === MeetingStatus::Draft;
	}

	/**
	 * Dokument zasedání (generuje se na požádání). download=1 vynutí stažení, named=1 jmenovité hlasování
	 * v zápisu, format=doc verze k úpravě ve Wordu.
	 */
	#[Allowed(Resource::Document)]
	public function actionDocument(int $id, string $type, bool $download = false, bool $named = false, string $format = 'pdf'): void
	{
		$meeting = $this->loadMeeting($id);
		$documentType = MeetingDocumentType::tryFrom($type) ?? $this->error('Neznámý typ dokumentu.');
		if (!$documentType->isAvailableFor($meeting->getStatus())) {
			$this->error('Dokument zatím nelze vytvořit.');
		}

		if ($format === 'doc' && !$documentType->isEditable()) {
			$this->error('Dokument nelze stáhnout pro Word.');
		}

		$document = $format === 'doc'
			? $this->documentFacade->generateEditable($meeting->getId(), $documentType, $named)
			: $this->documentFacade->generate($meeting->getId(), $documentType, $named);
		$download = $download || $format === 'doc';

		$response = $this->getHttpResponse();
		$response->setContentType($document->mimeType);
		$response->setHeader('Content-Disposition', sprintf(
			'%s; filename="%s"; filename*=UTF-8\'\'%s',
			$download ? 'attachment' : 'inline',
			$document->fileName,
			rawurlencode($document->fileName),
		));
		$response->setHeader('Content-Length', (string) strlen($document->content));
		$this->sendResponse(new CallbackResponse(static function () use ($document): void {
			echo $document->content;
		}));
	}

	/**
	 * Verze dokumentu z archivu (PDF) – náhled v prohlížeči, download=1 stažení.
	 */
	#[Allowed(Resource::Document)]
	public function actionArchived(int $id, int $documentId, bool $download = false): void
	{
		$meeting = $this->loadMeeting($id);
		try {
			$document = $this->assertOwned($this->archiveFacade->get($documentId));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}

		if ($document->getMeeting() !== $meeting) {
			$this->error('Dokument v archivu neexistuje.');
		}

		$this->getHttpResponse()->setHeader('X-Content-Type-Options', 'nosniff');
		$this->sendStoredFile($document->getFile(), forceDownload: $download);
	}

	/**
	 * Uloží aktuální podobu dokumentu do archivu (např. pozvánku v den vyvěšení).
	 */
	#[Allowed(Resource::Document, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleArchiveDocument(string $type, bool $named = false): void
	{
		$meeting = $this->meeting ?? $this->error();
		$documentType = MeetingDocumentType::tryFrom($type) ?? $this->error('Neznámý typ dokumentu.');
		try {
			$this->archiveFacade->archive($meeting->getId(), $documentType, $this->currentUserId(), namedVotes: $named);
			$this->flashMessage("Dokument „{$documentType->label()}“ byl uložen do archivu.", 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirectToDocuments($meeting);
	}

	/**
	 * Doplní konečné verze zápisu a usnesení, když se po ověření zápisu nepodařilo je uložit.
	 */
	#[Allowed(Resource::Document, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleArchiveFinal(): void
	{
		$meeting = $this->meeting ?? $this->error();
		try {
			$this->archiveFacade->archiveFinal($meeting->getId(), $this->currentUserId());
			$this->flashMessage('Konečné verze zápisu a usnesení jsou uložené v archivu.', 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirectToDocuments($meeting);
	}

	#[Allowed(Resource::Document, Privilege::Delete)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteArchived(int $documentId): void
	{
		$meeting = $this->meeting ?? $this->error();
		try {
			$document = $this->assertOwned($this->archiveFacade->get($documentId));
			if ($document->getMeeting() !== $meeting) {
				$this->error('Dokument v archivu neexistuje.');
			}

			$this->archiveFacade->delete($document->getId());
			$this->flashMessage('Verze byla z archivu smazána.', 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirectToDocuments($meeting);
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	public function actionLinks(int $id): void
	{
		$this->meeting = $this->loadMeeting($id);
	}

	public function renderLinks(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$this->template->meeting = $meeting;
		$this->template->tokens = $this->accessFacade->findByMeeting($meeting->getId());
		$this->template->members = $meeting->getBody()->getMembersAt($meeting->getScheduledAt());
		$this->template->issuedTokens = $this->issuedTokens;
		$this->template->issuedReason = $this->issuedReason;
		$this->template->mailLog = $this->invitationFacade->findLog($meeting->getId());
		$this->template->canInvite = $meeting->getStatus()->isPlanning();
		$this->template->portalLink = fn(IssuedToken $issued): string => $this->link('//:Portal:Entry:', [
			'token' => $issued->token,
		]);
		$this->template->now = $this->clock->now();
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleIssueLinks(?int $personId = null): void
	{
		$meeting = $this->meeting ?? $this->error();
		try {
			$this->issuedTokens = $personId === null
				? $this->accessFacade->issueForMembers($meeting->getId())
				: [$this->accessFacade->issueFor($meeting->getId(), $personId)];
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
			$this->redirect('this');
		}

		// odkazy se ukážou jen teď (v DB je jen hash) – stránka se proto nepřesměruje
		$this->getHttpResponse()->setHeader('Cache-Control', 'no-store');
	}

	/**
	 * Rozeslání pozvánek e-mailem (zasedání v přípravě se zároveň sdílí). Neodeslané odkazy se ukážou na stránce.
	 */
	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleSendInvitations(?int $personId = null): void
	{
		$meeting = $this->meeting ?? $this->error();
		try {
			if ($personId === null && $meeting->getStatus() === MeetingStatus::Draft) {
				foreach ($this->meetingFacade->transition($meeting->getId(), MeetingTransition::Share) as $warning) {
					$this->flashMessage($warning, 'warning');
				}
			}

			$result = $this->invitationFacade->send(
				$meeting->getId(),
				fn(string $token): string => $this->link('//:Portal:Entry:', [
					'token' => $token,
				]),
				$personId,
			);
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
			$this->redirect('this');
		}

		if ($result->sent !== []) {
			$this->flashMessage(count($result->sent) === 1
				? "Pozvánka byla odeslána ({$result->sent[0]->accessToken->getPerson()->getFullName()})."
				: 'Pozvánky byly odeslány ' . count($result->sent) . ' členům.', 'success');
		}

		if ($result->withoutEmail !== []) {
			$this->flashMessage('Bez e-mailu: ' . implode(', ', array_map(static fn(IssuedToken $t): string => $t->accessToken->getPerson()->getFullName(), $result->withoutEmail)) . '. Předejte jim odkaz níže.', 'warning');
		}

		if ($result->failed !== []) {
			$this->flashMessage('Nepodařilo se odeslat: ' . implode(', ', array_map(static fn(IssuedToken $t): string => $t->accessToken->getPerson()->getFullName(), $result->failed)) . '. Důvod je v přehledu odeslaných e-mailů, odkaz najdete níže.', 'danger');
		}

		if ($result->notDelivered() === []) {
			$this->redirect('this');
		}

		$this->issuedTokens = $result->notDelivered();
		$this->issuedReason = 'undelivered';
		$this->getHttpResponse()->setHeader('Cache-Control', 'no-store');
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleRevokeLink(int $tokenId): void
	{
		$meeting = $this->meeting ?? $this->error();
		foreach ($this->accessFacade->findByMeeting($meeting->getId()) as $token) {
			if ($token->getId() === $tokenId) {
				$this->accessFacade->revoke($tokenId);
				$this->flashMessage("Odkaz pro {$token->getPerson()->getFullName()} byl odvolán.", 'success');
				$this->redirect('this');
			}
		}

		$this->error('Odkaz neexistuje.');
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	public function actionOfficials(int $id): void
	{
		$this->meeting = $this->loadMeeting($id);
		if (!$this->meeting->canAssignOfficials()) {
			$this->flashMessage('Po ověření zápisu už funkce nelze měnit.', 'warning');
			$this->redirect('detail', [
				'id' => $id,
			]);
		}

		$this->getComponent('officialsForm')->setDefaults([
			'chairperson' => $this->meeting->getChairperson()?->getId(),
			'recorder' => $this->meeting->getRecorder()?->getId(),
			'firstVerifier' => $this->meeting->getFirstVerifier()?->getId(),
			'secondVerifier' => $this->meeting->getSecondVerifier()?->getId(),
		]);
	}

	public function renderOfficials(): void
	{
		$this->template->meeting = $this->meeting;
	}

	/**
	 * Přechody životního cyklu kromě znovuotevření (to má vlastní signál s vyšším oprávněním).
	 */
	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleTransition(string $transition): void
	{
		$meeting = $this->meeting ?? $this->error();
		$selected = MeetingTransition::tryFrom($transition);
		if ($selected === null || $selected->isReopening()) {
			$this->error('Neznámá akce.');
		}

		$this->runTransition($meeting, $selected);
	}

	/**
	 * Znovuotevření zasedání s důvodem (jen správce obce).
	 */
	#[Allowed(Resource::Meeting, Privilege::Manage)]
	public function actionReopen(int $id): void
	{
		$this->meeting = $this->loadMeeting($id);
		if (!in_array($this->meeting->getStatus(), [MeetingStatus::Closed, MeetingStatus::MinutesApproved], true)) {
			$this->flashMessage('Znovu otevřít lze jen ukončené zasedání nebo zasedání s ověřeným zápisem.', 'warning');
			$this->redirect('detail', [
				'id' => $id,
			]);
		}

		if ($this->meeting->isArchived()) {
			$submit = $this->getComponent('reopenForm')['reopen'];
			assert($submit instanceof \Nette\Forms\Controls\SubmitButton);
			$submit->setCaption(MeetingTransition::ReopenMinutes->label());
		}
	}

	public function renderReopen(): void
	{
		$this->template->meeting = $this->meeting;
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleInsertStandard(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$this->runProgramChange(function () use ($meeting): void {
			$added = $this->agendaFacade->insertStandardItems($meeting->getId());
			$this->flashMessage($added === 0 ? 'Standardní body už v programu jsou.' : "Do programu bylo vloženo {$added} standardních bodů.", 'success');
		});
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleApplyTemplate(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$templateId = $this->getRequest()->getPost('templateId');
		if (!is_numeric($templateId)) {
			$this->flashMessage('Vyberte šablonu programu.', 'warning');
			$this->redirect('this');
		}

		$this->runProgramChange(function () use ($meeting, $templateId): void {
			$template = $this->assertOwned($this->templateFacade->getMeetingTemplate((int) $templateId));
			$added = $this->templateFacade->applyToMeeting($template->getId(), $meeting->getId());
			$this->flashMessage("Do programu bylo vloženo {$added} bodů ze šablony „{$template->getName()}“.", 'success');
		});
	}

	#[Allowed(Resource::Template, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleSaveAsTemplate(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$name = $this->getRequest()->getPost('name');
		try {
			$template = $this->templateFacade->saveProgramAsTemplate($meeting->getId(), is_string($name) ? $name : '');
			$this->flashMessage("Program byl uložen jako šablona „{$template->getName()}“.", 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleMoveItem(int $itemId, int $position): void
	{
		$meeting = $this->meeting ?? $this->error();
		$item = $meeting->findAgendaItem($itemId) ?? $this->error('Bod programu neexistuje.');
		$this->runProgramChange(fn() => $this->agendaFacade->moveItem($item->getId(), $position));
	}

	/**
	 * Nové pořadí po přetažení myší (AJAX, POST order[] = ID bodů).
	 */
	#[Allowed(Resource::Agenda, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleReorder(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$order = $this->getRequest()->getPost('order');
		$ids = is_array($order) ? array_values(array_map(intval(...), array_filter($order, is_numeric(...)))) : [];
		$this->runProgramChange(fn() => $this->agendaFacade->reorder($meeting->getId(), $ids));
	}

	#[Allowed(Resource::Meeting, Privilege::Delete)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDelete(): void
	{
		$meeting = $this->meeting ?? $this->error();
		try {
			$this->meetingFacade->delete($meeting->getId());
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
			$this->redirect('detail', [
				'id' => $meeting->getId(),
			]);
		}

		$this->flashMessage('Zasedání bylo smazáno.', 'success');
		$this->redirect('default');
	}

	protected function createComponentReopenForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addTextArea('reason', 'Důvod znovuotevření', rows: 4)
			->setRequired('Uveďte důvod znovuotevření.')
			->addRule(Form::MinLength, 'Důvod musí mít alespoň %d znaků.', MeetingReopening::MinReasonLength)
			->addRule(Form::MaxLength, 'Důvod může mít nejvýše %d znaků.', MeetingReopening::MaxReasonLength)
			->setHtmlAttribute('placeholder', 'např. v zápisu chybí jmenovité hlasování u bodu 5, upozornil ověřovatel')
			->setOption('description', 'Důvod se uloží do historie zasedání spolu s vaším jménem a časem.');
		$form->addSubmit('reopen', 'Znovu otevřít');
		$form->onSuccess[] = $this->reopenFormSucceeded(...);

		return $form;
	}

	/**
	 * Nahrání podepsaného originálu (sken PDF) do archivu.
	 */
	protected function createComponentArchiveUploadForm(): Form
	{
		$meeting = $this->meeting ?? $this->error();
		$form = $this->formFactory->create();
		$types = [];
		foreach (MeetingDocumentType::cases() as $type) {
			if ($type->isAvailableFor($meeting->getStatus())) {
				$types[$type->value] = $type->label();
			}
		}

		$form->addSelect('type', 'Dokument', $types)
			->setDefaultValue(array_key_exists(MeetingDocumentType::Minutes->value, $types) ? MeetingDocumentType::Minutes->value : null)
			->setRequired();
		$form->addUpload('file', 'Soubor PDF')
			->setRequired('Vyberte soubor.')
			->addRule(Form::MimeType, 'Nahrajte sken jako PDF.', 'application/pdf')
			->addRule(Form::MaxFileSize, 'Soubor může mít nejvýše 64 MB.', DocumentArchiveFacade::MaxUploadSize)
			->setHtmlAttribute('accept', 'application/pdf,.pdf');
		$form->addText('note', 'Poznámka')
			->setNullable()
			->setMaxLength(255)
			->setHtmlAttribute('placeholder', 'např. podepsáno ověřovateli 20. 11.');
		$form->addSubmit('upload', 'Nahrát do archivu');
		$form->onSuccess[] = $this->archiveUploadFormSucceeded(...);

		return $form;
	}

	protected function createComponentMeetingForm(): Form
	{
		$form = $this->formFactory->create();

		if ($this->meeting === null) {
			$bodies = [];
			foreach ($this->findBodies() as $termName => $termBodies) {
				foreach ($termBodies as $body) {
					$bodies[$termName][$body->getId()] = $body->getName();
				}
			}

			$form->addSelect('body', 'Orgán', $bodies)
				->setRequired('Vyberte orgán.');
		}

		$types = [];
		foreach (MeetingType::cases() as $type) {
			$types[$type->value] = $type->label();
		}

		$form->addSelect('type', 'Typ zasedání', $types)
			->setDefaultValue(MeetingType::Regular->value)
			->setRequired();
		$form->addDateTime('scheduledAt', 'Datum a čas')
			->setRequired('Zadejte datum a čas zasedání.');
		$form->addText('place', 'Místo konání')
			->setRequired('Zadejte místo konání.')
			->setMaxLength(200)
			->setHtmlAttribute('placeholder', 'Zasedací místnost obecního úřadu');
		$form->addInteger('number', 'Pořadové číslo')
			->setNullable()
			->addRule(Form::Range, 'Pořadové číslo musí být 1 až 999.', [1, 999])
			->setOption('description', $this->meeting === null ? 'Nechte prázdné – doplní se další číslo v pořadí volebního období.' : 'Pořadí zasedání orgánu ve volebním období.');
		$form->addSubmit('save', $this->meeting === null ? 'Založit zasedání' : 'Uložit');
		$form->onSuccess[] = $this->meetingFormSucceeded(...);

		return $form;
	}

	protected function createComponentOfficialsForm(): Form
	{
		$meeting = $this->meeting ?? $this->error();
		$form = $this->formFactory->create();

		$members = [];
		foreach ($meeting->getBody()->getMembersAt($meeting->getScheduledAt()) as $membership) {
			$members[$membership->getPerson()->getId()] = $membership->getPerson()->getName()->sortable();
		}

		$persons = [];
		foreach ($this->peopleFacade->findPersons($meeting->getMunicipality()->getId()) as $person) {
			$persons[$person->getId()] = $person->getName()->sortable();
		}

		$form->addSelect('chairperson', 'Předsedající', $members)->setPrompt('— neurčeno —');
		$form->addSelect('firstVerifier', 'Ověřovatel', $members)->setPrompt('— neurčeno —');
		$form->addSelect('secondVerifier', 'Ověřovatel', $members)->setPrompt('— neurčeno —');
		$form->addSelect('recorder', 'Zapisovatel', $persons)
			->setPrompt('— neurčeno —')
			->setOption('description', 'Zapisovatelem může být i pracovník úřadu – stačí ho přidat mezi osoby.');
		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = function (Form $form) use ($meeting): void {
			$data = $form->getValues(OfficialsFormData::class);
			try {
				$this->meetingFacade->assignOfficials($meeting->getId(), new AssignOfficials(
					$data->chairperson,
					$data->recorder,
					$data->firstVerifier,
					$data->secondVerifier,
				));
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('Funkce byly uloženy.', 'success');
			$this->redirect('detail', [
				'id' => $meeting->getId(),
			]);
		};

		return $form;
	}

	private function reopenFormSucceeded(Form $form): void
	{
		$this->requirePermission(Resource::Meeting, Privilege::Manage);
		$meeting = $this->meeting ?? $this->error();
		/** @var array{reason: string} $values */
		$values = $form->getValues('array');
		try {
			$reopening = $this->meetingFacade->reopen($meeting->getId(), $this->currentUserId(), $values['reason']);
		} catch (ValidationException $e) {
			FormErrors::apply($form, $e);

			return;
		} catch (DomainException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->flashMessage($reopening->getToStatus() === MeetingStatus::Closed
			? 'Ověření zápisu bylo zrušeno. Zasedání je opět ukončené – průběh a zápis lze opravit a znovu ověřit.'
			: 'Zasedání bylo znovu otevřeno.', 'success');
		$this->redirect('detail', [
			'id' => $meeting->getId(),
		]);
	}

	private function archiveUploadFormSucceeded(Form $form): void
	{
		$this->requirePermission(Resource::Document, Privilege::Edit);
		$meeting = $this->meeting ?? $this->error();
		/** @var array{type: string, file: \Nette\Http\FileUpload, note: ?string} $values */
		$values = $form->getValues('array');
		$files = FormUploads::toUploadedFiles([$values['file']]);
		$type = MeetingDocumentType::tryFrom($values['type']);
		if ($files === [] || $type === null) {
			$form->addError('Soubor se nepodařilo nahrát.');

			return;
		}

		try {
			$this->archiveFacade->upload($meeting->getId(), $type, $files[0], $values['file']->getSize(), $this->currentUserId(), $values['note']);
		} catch (ValidationException $e) {
			FormErrors::apply($form, $e);

			return;
		} catch (DomainException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->flashMessage('Podepsaný originál byl uložen do archivu.', 'success');
		$this->redirectToDocuments($meeting);
	}

	private function redirectToDocuments(Meeting $meeting): never
	{
		$this->redirect('detail', [
			'id' => $meeting->getId(),
			'tab' => 'documents',
		]);
	}

	private function meetingFormSucceeded(Form $form): void
	{
		$data = $form->getValues(MeetingFormData::class);
		$command = new SaveMeeting(MeetingType::from($data->type), $data->scheduledAt, $data->place, $data->number);

		try {
			if ($this->meeting === null) {
				$body = $this->loadBody($data->body ?? $this->error());
				$meeting = $this->meetingFacade->create($body->getId(), $command);
				$this->flashMessage("Zasedání {$meeting->getTitle()} bylo založeno.", 'success');
			} else {
				$this->meetingFacade->update($this->meeting->getId(), $command);
				$meeting = $this->meeting;
				$this->flashMessage('Změny byly uloženy.', 'success');
			}
		} catch (ValidationException $e) {
			FormErrors::apply($form, $e);

			return;
		} catch (DomainException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('detail', [
			'id' => $meeting->getId(),
		]);
	}

	/**
	 * Změna programu: chybu ukáže jako flash, při AJAXu překreslí jen program.
	 *
	 * @param callable(): mixed $change
	 */
	private function runProgramChange(callable $change): void
	{
		$meeting = $this->meeting ?? $this->error();
		try {
			$change();
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		if ($this->isAjax()) {
			$this->meeting = $this->meetingFacade->get($meeting->getId());
			$this->redrawControl('program');
			$this->redrawControl('flashes');

			return;
		}

		$this->redirect('detail', [
			'id' => $meeting->getId(),
		]);
	}

	private function runTransition(Meeting $meeting, MeetingTransition $transition): never
	{
		try {
			$warnings = $this->meetingFacade->transition($meeting->getId(), $transition);
			if ($transition === MeetingTransition::ApproveMinutes) {
				$warnings = [...$warnings, ...$this->archiveFinalVersions($meeting)];
			}
			$this->flashMessage(match ($transition) {
				MeetingTransition::Share => 'Zasedání bylo sdíleno.',
				MeetingTransition::Start => 'Zasedání bylo zahájeno.',
				MeetingTransition::Close => 'Zasedání bylo ukončeno.',
				MeetingTransition::ApproveMinutes => 'Zápis je označen jako ověřený. Zasedání je uzavřené.',
				MeetingTransition::Reopen, MeetingTransition::ReopenMinutes => 'Zasedání bylo znovu otevřeno.',
			}, 'success');
			foreach ($warnings as $warning) {
				$this->flashMessage($warning, 'warning');
			}
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('detail', [
			'id' => $meeting->getId(),
		]);
	}

	/**
	 * Po ověření zápisu se konečný zápis a usnesení uloží do archivu. Selhání nevrací ověření zpět – jen upozorní.
	 *
	 * @return list<string> upozornění
	 */
	private function archiveFinalVersions(Meeting $meeting): array
	{
		try {
			$this->archiveFacade->archiveFinal($meeting->getId(), $this->currentUserId());

			return [];
		} catch (\Throwable $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::EXCEPTION);

			return ['Konečné verze dokumentů se nepodařilo uložit do archivu. Uložte je na záložce Dokumenty.'];
		}
	}

	/**
	 * Orgány obce seskupené podle volebního období (nejnovější období první).
	 *
	 * @return array<string, list<Body>>
	 */
	private function findBodies(): array
	{
		$grouped = [];
		foreach ($this->peopleFacade->findTerms($this->requireMunicipality()->getId()) as $term) {
			$bodies = $this->peopleFacade->findBodies($term->getId());
			if ($bodies !== []) {
				$grouped[$term->getName()] = $bodies;
			}
		}

		return $grouped;
	}

	private function loadMeeting(int $id): Meeting
	{
		try {
			return $this->assertOwned($this->meetingFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}

	private function loadBody(int $id): Body
	{
		try {
			return $this->assertOwned($this->peopleFacade->getBody($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
