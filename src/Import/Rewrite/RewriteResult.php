<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Rewrite;

final class RewriteResult
{
    /**
     * @param array<int|string, mixed> $data    the content of one language with all references rewritten
     * @param list<RemovedReference>   $removed
     */
    public function __construct(
        public readonly array $data,
        public readonly array $removed,
    ) {
    }
}
