<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\MediaBundle\Entity\CollectionInterface;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Images of this system through Doctrine; files are read through Sulu's storage service.
 */
class DoctrineMediaTarget implements MediaTarget
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageInterface $storage,
    ) {
    }

    public function exists(int $id): bool
    {
        return $this->entityManager->getRepository(MediaInterface::class)->find($id) instanceof MediaInterface;
    }

    public function candidates(string $filename, int $size): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT m.id AS id')
            ->from(MediaInterface::class, 'm')
            ->join('m.files', 'f')
            ->join('f.fileVersions', 'fv')
            ->where('fv.version = f.version')
            ->andWhere('fv.name = :name')
            ->andWhere('fv.size = :size')
            ->setParameter('name', $filename)
            ->setParameter('size', $size)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function sha256(int $id): ?string
    {
        $media = $this->entityManager->getRepository(MediaInterface::class)->find($id);
        if (!$media instanceof MediaInterface) {
            return null;
        }

        $file = $media->getFiles()->first();
        if (!$file instanceof File) {
            return null;
        }
        $fileVersion = $file->getFileVersion($file->getVersion());
        if (!$fileVersion instanceof FileVersion) {
            return null;
        }

        try {
            $stream = $this->storage->load($fileVersion->getStorageOptions());
        } catch (IOException) {
            return null;
        }

        $context = hash_init('sha256');
        while (!feof($stream)) {
            $chunk = fread($stream, 1 << 20);
            if (false === $chunk) {
                break;
            }
            hash_update($context, $chunk);
        }
        if (\is_resource($stream)) {
            fclose($stream);
        }

        return hash_final($context);
    }

    public function collectionIdByKey(string $key): ?int
    {
        $collection = $this->entityManager->getRepository(CollectionInterface::class)->findOneBy(['key' => $key]);

        return $collection instanceof CollectionInterface ? (int) $collection->getId() : null;
    }
}
