<?php

declare(strict_types=1);

namespace App\Model\Recording;

use App\Model\Meeting\MeetingRepository;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\Storage\FileStorage;
use App\Model\Storage\UploadedFile;
use App\Model\User\UserRepository;

/**
 * Zvukové záznamy zasedání: nahrání (i více souborů najednou), přehrání, smazání.
 */
final readonly class RecordingFacade
{
	public const int MaxSize = 500 * 1024 * 1024;

	public const array AllowedExtensions = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'webm', 'flac', 'amr', 'wma', 'mp4'];

	public function __construct(
		private RecordingRepository $recordings,
		private MeetingRepository $meetings,
		private UserRepository $users,
		private FileStorage $storage,
		private TransactionManager $transaction,
	) {}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Recording
	{
		return $this->recordings->get($id);
	}

	/**
	 * @return list<Recording>
	 */
	public function findByMeeting(int $meetingId): array
	{
		return $this->recordings->findByMeeting($this->meetings->get($meetingId));
	}

	/**
	 * @param list<UploadedFile> $files
	 * @return list<Recording>
	 * @throws ValidationException soubor není zvukový záznam nebo je příliš velký
	 */
	public function upload(int $meetingId, array $files, ?int $userId): array
	{
		$meeting = $this->meetings->get($meetingId);
		foreach ($files as $file) {
			self::assertAllowed($file);
		}

		$stored = [];
		try {
			foreach ($files as $file) {
				$item = $this->storage->store($file->stream, $file->originalName, "m{$meeting->getMunicipality()->getId()}/recordings");
				$stored[] = $item;
				if (!self::isAudio($item)) {
					throw new ValidationException("Soubor „{$file->originalName}“ není zvukový záznam.", 'files');
				}
			}

			return $this->transaction->run(function () use ($meetingId, $stored, $userId): array {
				$meeting = $this->meetings->get($meetingId);
				$user = $userId === null ? null : $this->users->find($userId);

				return array_map(function (StoredFile $file) use ($meeting, $user): Recording {
					$recording = new Recording($meeting, $file, $user);
					$this->recordings->add($recording);

					return $recording;
				}, $stored);
			});
		} catch (\Throwable $e) {
			array_walk($stored, $this->storage->delete(...));

			throw $e;
		}
	}

	/**
	 * @throws \App\Model\Shared\Exception\InvalidStateException zápis zasedání je ověřen
	 */
	public function delete(int $recordingId): void
	{
		$file = $this->transaction->run(function () use ($recordingId): StoredFile {
			$recording = $this->recordings->get($recordingId);
			$recording->assertDeletable();
			$this->recordings->remove($recording);

			return $recording->getFile();
		});

		$this->storage->delete($file);
	}

	/**
	 * @throws ValidationException
	 */
	private static function assertAllowed(UploadedFile $file): void
	{
		$extension = strtolower(pathinfo($file->originalName, PATHINFO_EXTENSION));
		if (!in_array($extension, self::AllowedExtensions, true)) {
			throw new ValidationException("Soubor „{$file->originalName}“ není zvukový záznam (povolené jsou MP3, M4A, WAV, OGG, OPUS, FLAC…).", 'files');
		}

		$stat = fstat($file->stream);
		$size = $stat === false ? 0 : $stat['size'];
		if ($size === 0) {
			throw new ValidationException("Soubor „{$file->originalName}“ je prázdný.", 'files');
		}

		if ($size > self::MaxSize) {
			throw new ValidationException(sprintf('Soubor „%s“ je příliš velký (max. %d MB).', $file->originalName, intdiv(self::MaxSize, 1024 * 1024)), 'files');
		}
	}

	/**
	 * Typ podle obsahu souboru (ne podle přípony).
	 */
	private static function isAudio(StoredFile $file): bool
	{
		return strncmp($file->mimeType, 'audio/', 6) === 0
			|| in_array($file->mimeType, ['video/mp4', 'video/webm', 'application/ogg', 'video/ogg'], true);
	}
}
