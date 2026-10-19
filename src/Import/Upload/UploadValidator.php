<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Upload;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Exception\UploadTooLargeException;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tells whether a request carries a usable package file. A package that is too large for the server (the limits of PHP
 * or the maximum package size of the bundle) is reported as such, so the admin can point to the console as a way out.
 */
class UploadValidator
{
    /**
     * @param ?int $postMaxSize       post_max_size in bytes, null: read it from PHP (0: no limit)
     * @param ?int $uploadMaxFilesize upload_max_filesize in bytes, null: read it from PHP (0: no limit)
     */
    public function __construct(
        private readonly PackageLimits $limits,
        private readonly ?int $postMaxSize = null,
        private readonly ?int $uploadMaxFilesize = null,
    ) {
    }

    /**
     * @throws UploadTooLargeException when the server did not accept the upload because of its size
     * @throws ContentTransferException for any other upload that cannot be used
     */
    public function validate(Request $request): UploadedFile
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            // PHP drops the whole request (files and fields) when it is larger than post_max_size
            $postMax = $this->postMaxSize ?? self::iniBytes('post_max_size');
            $length = (int) $request->headers->get('Content-Length', '0');
            if ($postMax > 0 && $length > $postMax) {
                throw new UploadTooLargeException($postMax);
            }

            throw new ContentTransferException('No package was uploaded (form field "file").', 'import.no_file');
        }

        if (!$file->isValid()) {
            if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                $limit = $this->uploadMaxFilesize ?? self::iniBytes('upload_max_filesize');

                throw new UploadTooLargeException($limit > 0 ? $limit : null);
            }

            throw new ContentTransferException('The upload failed: '.$file->getErrorMessage(), 'import.upload_failed');
        }

        if ($file->getSize() > $this->limits->maxPackageSize) {
            throw new UploadTooLargeException($this->limits->maxPackageSize);
        }

        return $file;
    }

    /**
     * A PHP size setting ("2M", "8G", "0") in bytes; 0 means no limit.
     */
    private static function iniBytes(string $name): int
    {
        $value = trim((string) \ini_get($name));
        if ('' === $value || !preg_match('/^(-?\d+)\s*([kmg]?)b?$/i', $value, $matches)) {
            return 0;
        }

        $bytes = (int) $matches[1] * match (strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };

        return max(0, $bytes);
    }
}
