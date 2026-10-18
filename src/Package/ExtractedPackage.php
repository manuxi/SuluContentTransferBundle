<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Package;

use Manuxi\SuluContentTransferBundle\Format\MediaEntry;
use Manuxi\SuluContentTransferBundle\Format\Package;

/**
 * A package that was read and unpacked into its own subdirectory of the work directory. The caller is responsible
 * for removing it again via WorkDirectory::remove($token) (or the purge of old directories).
 */
final class ExtractedPackage
{
    public function __construct(
        public readonly string $token,
        public readonly string $directory,
        public readonly Package $package,
    ) {
    }

    /**
     * Absolute path of the unpacked file of a medium.
     */
    public function mediaFilePath(MediaEntry $entry): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $entry->filePath());
    }
}
