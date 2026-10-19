<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;

/**
 * @extends EntityRepository<TransferMapping>
 */
class TransferMappingRepository extends EntityRepository implements TransferMappings
{
    public function findMapping(string $sourceInstance, string $resourceKey, string $sourceId): ?TransferMapping
    {
        return $this->findOneBy([
            'sourceInstance' => $sourceInstance,
            'resourceKey' => $resourceKey,
            'sourceId' => $sourceId,
        ]);
    }

    public function add(TransferMapping $mapping): void
    {
        $this->getEntityManager()->persist($mapping);
    }
}
