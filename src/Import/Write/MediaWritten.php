<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

final class MediaWritten
{
    /**
     * @param list<string> $warnings things of the package that could not be taken over
     */
    public function __construct(
        public readonly int $id,
        public readonly array $warnings = [],
    ) {
    }
}
