<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\EventListener;

use Manuxi\SuluContentTransferBundle\Controller\Admin\ImportErrorResponse;
use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Import\Upload\UploadValidator;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Answers an unusable upload to the import before anything else looks at it. The media bundle of Sulu inspects every
 * uploaded file of every request and fails with a server error on a file that PHP refused (larger than
 * upload_max_filesize): the editor would see "failed (500)" instead of the hint to import on the console.
 *
 * Runs after the firewall and the router, but before the listener of the media bundle.
 */
class ImportUploadListener
{
    public const ROUTE = 'sulu_content_transfer.import_analyze';

    public function __construct(private readonly UploadValidator $validator)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || self::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        try {
            $this->validator->validate($request);
        } catch (ContentTransferException $exception) {
            $event->setResponse(ImportErrorResponse::for($exception));
        }
    }
}
