<?php

declare(strict_types=1);

namespace App\Application\Municipality;

use App\Application\Municipality\Command\CreateMunicipality;
use App\Application\Municipality\Command\UpdateMunicipalitySettings;
use App\Application\Shared\Storage\FileStorage;
use App\Application\Shared\Storage\UploadedFile;
use App\Application\Shared\TransactionManager;
use App\Domain\Municipality\Municipality;
use App\Domain\Municipality\MunicipalityProfile;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\ValueObject\Email;
use App\Domain\Shared\ValueObject\Ico;
use Nette\Utils\Strings;

final readonly class MunicipalityFacade
{
	/**
	 * Formáty loga použitelné i v DOCX (PhpWord).
	 */
	public const array LogoMimeTypes = ['image/png', 'image/jpeg'];

	public function __construct(
		private MunicipalityRepository $municipalities,
		private TransactionManager $transaction,
		private FileStorage $fileStorage,
	) {}

	/**
	 * @throws ValidationException
	 */
	public function create(CreateMunicipality $command): Municipality
	{
		return $this->transaction->run(function () use ($command): Municipality {
			$slug = $command->slug ?? Strings::webalize($command->name);
			$ico = Ico::tryFrom($command->ico);

			if ($this->municipalities->findBySlug($slug) !== null) {
				throw new ValidationException("Obec se zkratkou „{$slug}“ už existuje.", 'slug');
			}

			if ($ico !== null && $this->municipalities->findByIco($ico) !== null) {
				throw new ValidationException("Obec s IČO {$ico} už existuje.", 'ico');
			}

			$municipality = new Municipality($command->name, $slug, $ico);
			$this->municipalities->add($municipality);

			return $municipality;
		});
	}

	/**
	 * @throws ValidationException
	 */
	public function updateSettings(int $municipalityId, UpdateMunicipalitySettings $command): void
	{
		$profile = new MunicipalityProfile(
			officialName: $command->officialName,
			street: $command->street,
			city: $command->city,
			postalCode: $command->postalCode,
			email: $command->email === null || trim($command->email) === '' ? null : new Email($command->email),
			phone: $command->phone,
			website: $command->website,
			population: $command->population,
		);
		$ico = Ico::tryFrom($command->ico);

		$this->transaction->run(function () use ($municipalityId, $command, $profile, $ico): void {
			$municipality = $this->municipalities->get($municipalityId);

			if ($ico !== null) {
				$other = $this->municipalities->findByIco($ico);
				if ($other !== null && $other !== $municipality) {
					throw new ValidationException("Obec s IČO {$ico} už v systému existuje.", 'ico');
				}
			}

			$municipality->rename($command->name);
			$municipality->changeIco($ico);
			$municipality->updateProfile($profile);
		});
	}

	/**
	 * Nahradí logo obce. Starý soubor se smaže až po úspěšném uložení.
	 *
	 * @throws ValidationException
	 */
	public function changeLogo(int $municipalityId, UploadedFile $upload): void
	{
		$file = $this->fileStorage->store($upload->stream, $upload->originalName, "m{$municipalityId}/logo");

		if (!in_array($file->mimeType, self::LogoMimeTypes, true)) {
			$this->fileStorage->delete($file);
			throw new ValidationException('Logo musí být obrázek PNG nebo JPEG.', 'logo');
		}

		try {
			$previous = $this->transaction->run(function () use ($municipalityId, $file) {
				$municipality = $this->municipalities->get($municipalityId);
				$previous = $municipality->getLogo();
				$municipality->changeLogo($file);

				return $previous;
			});
		} catch (\Throwable $e) {
			$this->fileStorage->delete($file);

			throw $e;
		}

		if ($previous !== null) {
			$this->fileStorage->delete($previous);
		}
	}

	public function removeLogo(int $municipalityId): void
	{
		$previous = $this->transaction->run(function () use ($municipalityId) {
			$municipality = $this->municipalities->get($municipalityId);
			$previous = $municipality->getLogo();
			$municipality->changeLogo(null);

			return $previous;
		});

		if ($previous !== null) {
			$this->fileStorage->delete($previous);
		}
	}

	public function get(int $id): Municipality
	{
		return $this->municipalities->get($id);
	}

	public function findBySlug(string $slug): ?Municipality
	{
		return $this->municipalities->findBySlug($slug);
	}

	/**
	 * @return list<Municipality>
	 */
	public function findAll(): array
	{
		return $this->municipalities->findAll();
	}
}
