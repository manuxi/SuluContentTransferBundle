<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Repository;

use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;

/**
 * The mapping table: the analysis reads it, the import writes it.
 */
interface TransferMappings
{
    public function findMapping(string $sourceInstance, string $resourceKey, string $sourceId): ?TransferMapping;

    /**
     * Stores a new row (the caller flushes, usually together with the content it belongs to).
     */
    public function add(TransferMapping $mapping): void;
}
