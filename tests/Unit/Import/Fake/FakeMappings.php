<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;
use Manuxi\SuluContentTransferBundle\Repository\TransferMappings;

final class FakeMappings implements TransferMappings
{
    /** @var array<string, TransferMapping> */
    private array $mappings = [];

    public function add(TransferMapping $mapping): void
    {
        $this->mappings[$mapping->getSourceInstance()."\0".$mapping->getResourceKey()."\0".$mapping->getSourceId()] = $mapping;
    }

    public function findMapping(string $sourceInstance, string $resourceKey, string $sourceId): ?TransferMapping
    {
        return $this->mappings[$sourceInstance."\0".$resourceKey."\0".$sourceId] ?? null;
    }
}
