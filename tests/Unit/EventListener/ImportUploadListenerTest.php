<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\EventListener;

use Manuxi\SuluContentTransferBundle\EventListener\ImportUploadListener;
use Manuxi\SuluContentTransferBundle\Import\Upload\UploadValidator;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ImportUploadListenerTest extends TemporaryDirectoryTestCase
{
    public function testAnUploadPhpRefusedForItsSizeIsAnsweredBeforeAnythingInspectsIt(): void
    {
        $file = new UploadedFile($this->directory.'/none', 'big.sulu-transfer.zip', 'application/zip', \UPLOAD_ERR_INI_SIZE, true);

        $event = $this->handle($this->request(['file' => $file]));

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(413, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('import.upload_too_large', $body['code']);
        $this->assertSame(2 * 1024 * 1024, $body['limit']);
    }

    public function testAnotherBrokenUploadIsAnsweredToo(): void
    {
        $file = new UploadedFile($this->directory.'/none', 'p.zip', 'application/zip', \UPLOAD_ERR_PARTIAL, true);

        $response = $this->handle($this->request(['file' => $file]))->getResponse();

        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testAUsableUploadIsLeftToTheController(): void
    {
        $path = $this->directory.'/package.zip';
        file_put_contents($path, 'zip');

        $this->assertNull($this->handle($this->request(['file' => new UploadedFile($path, 'package.zip', 'application/zip', null, true)]))->getResponse());
    }

    public function testOtherRoutesAreNotTouched(): void
    {
        $file = new UploadedFile($this->directory.'/none', 'big.zip', 'application/zip', \UPLOAD_ERR_INI_SIZE, true);

        $this->assertNull($this->handle($this->request(['file' => $file], 'sulu_media.post_media'))->getResponse());
        $this->assertNull($this->handle($this->request(['file' => $file], null))->getResponse());
    }

    public function testSubRequestsAreNotTouched(): void
    {
        $file = new UploadedFile($this->directory.'/none', 'big.zip', 'application/zip', \UPLOAD_ERR_INI_SIZE, true);

        $this->assertNull($this->handle($this->request(['file' => $file]), HttpKernelInterface::SUB_REQUEST)->getResponse());
    }

    /**
     * @param array<string, UploadedFile> $files
     */
    private function request(array $files, ?string $route = ImportUploadListener::ROUTE): Request
    {
        $request = new Request([], [], null === $route ? [] : ['_route' => $route], [], $files, ['REQUEST_METHOD' => 'POST']);

        return $request;
    }

    private function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $listener = new ImportUploadListener(new UploadValidator(new PackageLimits(), 8 * 1024 * 1024, 2 * 1024 * 1024));
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);

        $listener->onKernelRequest($event);

        return $event;
    }
}
