<?php

declare(strict_types=1);

namespace App\AdminModule\Attachment;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Resource;
use App\Model\Attachment\Attachment;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Shared\Exception\NotFoundException;

/**
 * Stažení a náhled příloh – vždy přes kontrolu oprávnění a příslušnosti k obci.
 */
#[Allowed(Resource::Attachment)]
final class AttachmentPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly AttachmentFacade $attachmentFacade,
	) {
		parent::__construct();
	}

	public function actionDownload(int $id): void
	{
		$this->sendStoredFile($this->loadAttachment($id)->getFile());
	}

	/**
	 * Náhled v prohlížeči jen pro PDF a rastrové obrázky, ostatní typy se stáhnou.
	 */
	public function actionPreview(int $id): void
	{
		$attachment = $this->loadAttachment($id);
		$this->getHttpResponse()->setHeader('X-Content-Type-Options', 'nosniff');
		$this->getHttpResponse()->setHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
		$this->sendStoredFile($attachment->getFile(), forceDownload: !$attachment->canPreview());
	}

	private function loadAttachment(int $id): Attachment
	{
		try {
			return $this->assertOwned($this->attachmentFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
