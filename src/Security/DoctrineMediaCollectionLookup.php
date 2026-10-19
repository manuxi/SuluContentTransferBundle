<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\MediaBundle\Entity\CollectionInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;

/**
 * Folders of images through Doctrine.
 */
class DoctrineMediaCollectionLookup implements MediaCollectionLookup
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function collectionOfMedium(int $mediumId): ?array
    {
        $medium = $this->entityManager->getRepository(MediaInterface::class)->find($mediumId);

        return $medium instanceof MediaInterface ? $this->describe($medium->getCollection()) : null;
    }

    public function collection(int $collectionId): ?array
    {
        $collection = $this->entityManager->getRepository(CollectionInterface::class)->find($collectionId);

        return $collection instanceof CollectionInterface ? $this->describe($collection) : null;
    }

    /**
     * @return array{id: int, system: bool}
     */
    private function describe(CollectionInterface $collection): array
    {
        return [
            'id' => (int) $collection->getId(),
            'system' => SystemCollectionManagerInterface::COLLECTION_TYPE === $collection->getType()->getKey(),
        ];
    }
}
