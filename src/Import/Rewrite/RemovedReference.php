<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Rewrite;

/**
 * A reference that the import removed from the content because its target does not exist on this system.
 */
final class RemovedReference
{
    public function __construct(
        public readonly string $locale,
        public readonly string $path,
        public readonly string $kind,
        public readonly int|string $id,
    ) {
    }

    /**
     * @return array{locale: string, path: string, kind: string, id: int|string}
     */
    public function toArray(): array
    {
        return ['locale' => $this->locale, 'path' => $this->path, 'kind' => $this->kind, 'id' => $this->id];
    }
}
