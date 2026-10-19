<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

/**
 * What the import analysis asks this system about images.
 */
interface MediaTarget
{
    public function exists(int $id): bool;

    /**
     * Media whose current file has this name and size: the candidates for "the same image".
     *
     * @return list<int>
     */
    public function candidates(string $filename, int $size): array;

    /**
     * SHA-256 of the current file of a medium as it is stored, or null if the file cannot be read.
     */
    public function sha256(int $id): ?string;

    /**
     * Folder of this system with the given key, if there is one.
     */
    public function collectionIdByKey(string $key): ?int;
}
