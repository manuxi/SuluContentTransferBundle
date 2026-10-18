<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Manuxi\SuluContentTransferBundle\Format\MediaEntry;

final class MediaExport
{
    /**
     * @param array<int, MediaEntry>     $entries all media that were found, by id
     * @param array<int, string>         $files   local copy of the original file, by id (only when the files go into the package)
     * @param list<array<string, mixed>> $rows    id, filename, size, sha256 for the lookup of the manifest
     * @param list<int>                  $missing ids the system does not know or that have no file
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $files,
        public readonly array $rows,
        public readonly array $missing,
    ) {
    }
}
