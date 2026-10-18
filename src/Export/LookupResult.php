<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Manuxi\SuluContentTransferBundle\Format\ReferenceLookup;

final class LookupResult
{
    /**
     * @param array<string, list<int|string>> $missing       referenced ids the source system does not know (any more), by kind
     * @param array<string, list<int|string>> $withoutLookup referenced ids of kinds the lookup has no rows for, by kind
     */
    public function __construct(
        public readonly ReferenceLookup $lookup,
        public readonly array $missing = [],
        public readonly array $withoutLookup = [],
    ) {
    }
}
