<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Repository;

use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;

/**
 * Read access to the mapping table; the import analysis only needs this part of the repository.
 */
interface TransferMappings
{
    public function findMapping(string $sourceInstance, string $resourceKey, string $sourceId): ?TransferMapping;
}
