<?php

declare(strict_types=1);

namespace App\Model\Project;

use App\Model\Attachment\Attachment;
use App\Model\Attachment\AttachmentPolicy;
use App\Model\Attachment\AttachmentRepository;
use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\AgendaItemRepository;
use App\Model\Municipality\MunicipalityRepository;
use App\Model\People\PersonRepository;
use App\Model\Project\Command\SaveProject;
use App\Model\Resolution\ResolutionRepository;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Shared\ValueObject\Email;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\Storage\FileStorage;
use App\Model\Storage\UploadedFile;
use App\Model\User\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Úkolníček: dlouhodobé úkoly, jejich fáze, soubory, propojení s body zasedání a vlákno událostí.
 */
final readonly class ProjectFacade
{
	public function __construct(
		private ProjectRepository $projects,
		private MunicipalityRepository $municipalities,
		private PersonRepository $persons,
		private UserRepository $users,
		private AgendaItemRepository $agendaItems,
		private AttachmentRepository $attachments,
		private AttachmentPolicy $policy,
		private FileStorage $storage,
		private CommentFacade $comments,
		private ResolutionRepository $resolutions,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	public function create(int $municipalityId, SaveProject $command, string $createdByName, ProjectStatus $status = ProjectStatus::Idea): Project
	{
		return $this->transaction->run(function () use ($municipalityId, $command, $createdByName, $status): Project {
			$project = new Project($this->municipalities->get($municipalityId), $this->details($command), $createdByName, $status);
			$this->projects->add($project);

			return $project;
		});
	}

	/**
	 * Nejvýše tolik podnětů za hodinu z jedné IP adresy.
	 */
	public const int ProposalsPerHour = 3;

	/**
	 * Podnět občana z veřejné stránky obce (stav „nový podnět“, neveřejný do posouzení).
	 *
	 * @throws ValidationException
	 */
	public function submitProposal(int $municipalityId, string $name, string $email, string $title, ?string $description, ?string $ip): Project
	{
		if ($ip !== null && $this->projects->countProposalsFromIp($ip, $this->clock->now()->modify('-1 hour')) >= self::ProposalsPerHour) {
			throw new ValidationException('Z vašeho připojení bylo odesláno příliš mnoho podnětů. Zkuste to prosím za hodinu.');
		}

		$details = new ProjectDetails($title, $description);
		$contact = new Email($email);

		return $this->transaction->run(function () use ($municipalityId, $details, $name, $contact, $ip): Project {
			$project = Project::citizenProposal($this->municipalities->get($municipalityId), $details, $name, $contact, $ip);
			$this->projects->add($project);

			return $project;
		});
	}

	public function update(int $projectId, SaveProject $command): void
	{
		$this->transaction->run(function () use ($projectId, $command): void {
			$this->projects->get($projectId)->update($this->details($command));
		});
	}

	/**
	 * @throws InvalidStateException stejná fáze
	 */
	public function changeStatus(int $projectId, ProjectStatus $status, string $changedByName, ?string $note = null): void
	{
		$this->transaction->run(function () use ($projectId, $status, $changedByName, $note): void {
			$this->projects->get($projectId)->changeStatus($status, $this->clock->now(), $changedByName, $note);
		});
	}

	public function setPublic(int $projectId, bool $public): void
	{
		$this->transaction->run(function () use ($projectId, $public): void {
			$project = $this->projects->get($projectId);
			$public ? $project->publish() : $project->unpublish();
		});
	}

	/**
	 * Propojí bod programu s úkolem (null = zrušit propojení).
	 */
	public function linkAgendaItem(int $itemId, ?int $projectId): void
	{
		$this->transaction->run(function () use ($itemId, $projectId): void {
			$this->agendaItems->get($itemId)->linkProject($projectId === null ? null : $this->projects->get($projectId));
		});
	}

	/**
	 * @param non-empty-list<UploadedFile> $files
	 * @return list<Attachment>
	 */
	public function uploadFiles(int $projectId, array $files, ?int $userId): array
	{
		$project = $this->projects->get($projectId);
		foreach ($files as $file) {
			$stat = fstat($file->stream);
			$this->policy->assertAllowed($file->originalName, $stat === false ? 0 : $stat['size']);
		}

		$stored = [];
		try {
			foreach ($files as $file) {
				$stored[] = $this->storage->store($file->stream, $file->originalName, "m{$project->getMunicipality()->getId()}/projects");
			}

			return $this->transaction->run(function () use ($projectId, $stored, $userId): array {
				$project = $this->projects->get($projectId);
				$user = $userId === null ? null : $this->users->get($userId);

				return array_map(function (StoredFile $file) use ($project, $user): Attachment {
					$attachment = Attachment::forProject($project, $file, $user);
					$this->attachments->add($attachment);

					return $attachment;
				}, $stored);
			});
		} catch (\Throwable $e) {
			array_walk($stored, fn(StoredFile $file) => $this->storage->delete($file));

			throw $e;
		}
	}

	public function get(int $projectId): Project
	{
		return $this->projects->get($projectId);
	}

	/**
	 * @return list<Project>
	 */
	public function find(int $municipalityId, ?ProjectStatus $status = null, bool $onlyActive = false): array
	{
		return $this->projects->findByMunicipality($this->municipalities->get($municipalityId), $status, $onlyActive);
	}

	/**
	 * @return list<Project>
	 */
	public function findPublic(int $municipalityId): array
	{
		return $this->projects->findPublic($this->municipalities->get($municipalityId));
	}

	/**
	 * @return array<string, int>
	 */
	public function countByStatus(int $municipalityId): array
	{
		return $this->projects->countByStatus($this->municipalities->get($municipalityId));
	}

	/**
	 * @return list<AgendaItem>
	 */
	public function findLinkedItems(int $projectId): array
	{
		return $this->agendaItems->findByProject($this->projects->get($projectId));
	}

	/**
	 * Body zasedání za poslední rok, které lze s úkolem propojit.
	 *
	 * @return list<AgendaItem>
	 */
	public function findLinkableItems(int $projectId): array
	{
		$project = $this->projects->get($projectId);

		return array_values(array_filter(
			$this->agendaItems->findRecent($project->getMunicipality(), $this->clock->now()->modify('-1 year')),
			static fn(AgendaItem $item): bool => $item->getProject() !== $project,
		));
	}

	/**
	 * Vlákno úkolu: vznik, změny fáze, body zasedání, soubory a diskuse – chronologicky.
	 *
	 * @param bool $publicOnly pohled občana: jen zveřejněné komentáře, bez souborů úřadu
	 * @return list<TimelineEntry>
	 */
	public function timeline(int $projectId, bool $publicOnly = false): array
	{
		$project = $this->projects->get($projectId);
		$entries = [TimelineEntry::created($project)];
		foreach ($project->getStatusChanges() as $change) {
			$entries[] = TimelineEntry::status($change);
		}

		foreach ($this->agendaItems->findByProject($project) as $item) {
			$entries[] = TimelineEntry::agenda($item, $this->resolutions->findByAgendaItem($item));
		}

		if (!$publicOnly) {
			foreach ($this->attachments->findByProject($project) as $attachment) {
				$entries[] = TimelineEntry::file($attachment);
			}
		}

		foreach ($this->comments->findProjectThreads($project->getId(), $publicOnly) as $thread) {
			$entries[] = TimelineEntry::thread($thread);
		}

		usort($entries, static fn(TimelineEntry $a, TimelineEntry $b): int => $a->at <=> $b->at);

		return $entries;
	}

	private function details(SaveProject $command): ProjectDetails
	{
		if ($command->estimatedCost !== null && $command->estimatedCost > 100_000_000_000) {
			throw new ValidationException('Odhad nákladů je mimo rozsah.', 'estimatedCost');
		}

		return new ProjectDetails(
			$command->title,
			$command->description,
			$command->priority,
			$command->responsibleId === null ? null : $this->persons->get($command->responsibleId),
			$command->estimatedCost,
		);
	}
}
