<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

use Manuxi\SuluContentTransferBundle\Package\PackagePath;

/**
 * One medium of the package: its meta data (media/<id>/meta.json) and the place of its file in the package
 * (media/<id>/<file name>).
 */
final class MediaEntry
{
    /**
     * @param int    $id       id of the medium on the source system
     * @param string $fileName name of the file inside the package; may differ from the original name in the meta
     *                         data if that is not allowed in a package
     */
    public function __construct(
        public readonly int $id,
        public readonly MediaMeta $meta,
        public readonly string $fileName,
    ) {
        PackagePath::mediaFile($id, $fileName);
    }

    public function filePath(): string
    {
        return PackagePath::mediaFile($this->id, $this->fileName);
    }

    public function metaPath(): string
    {
        return PackagePath::mediaMeta($this->id);
    }
}
