<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Manuxi\SuluContentTransferBundle\Format\Package;

/**
 * The outcome of an export: the package, the local copies of the image files that go into it and a report for the user.
 */
final class ExportResult
{
    /**
     * @param array<int, string>                $mediaFiles     media id → local file for the package writer
     * @param list<ExportedArticle>             $articles       one entry per requested article, in the requested order
     * @param list<string>                      $warnings       things the user should know (missing images, references without lookup)
     * @param array<string, list<int|string>>   $missing        referenced ids the system no longer knows, by kind
     * @param array<string, list<int|string>>   $withoutLookup  referenced ids of kinds the manifest cannot describe, by kind
     * @param array<string, int>                $referenceCount number of references found, by kind
     */
    public function __construct(
        public readonly Package $package,
        public readonly array $mediaFiles,
        public readonly array $articles,
        public readonly array $warnings,
        public readonly array $missing,
        public readonly array $withoutLookup,
        public readonly array $referenceCount,
        public readonly string $workToken,
    ) {
    }

    public function mediaCount(): int
    {
        return \count($this->package->manifest->references->media);
    }

    public function mediaBytes(): int
    {
        $bytes = 0;
        foreach ($this->package->manifest->references->media as $row) {
            $bytes += $row['size'];
        }

        return $bytes;
    }
}
