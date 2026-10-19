<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Manuxi\SuluContentTransferBundle\DependencyInjection\SuluContentTransferExtension;
use Manuxi\SuluContentTransferBundle\Format\MediaEntry;
use Manuxi\SuluContentTransferBundle\Format\Package;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Import\Target\MediaTarget;
use Manuxi\SuluContentTransferBundle\Repository\TransferMappings;

/**
 * Decides for every image the articles use whether this system already has it (Plan E6):
 *
 *  1. the mapping table knows the image of this source: that medium is used; if its file differs from the package
 *     and the package has the file, it gets a new file version instead of a second image;
 *  2. a medium with the same name and size whose file has the same SHA-256 is used;
 *  3. otherwise the image is created from the package: in the folder with the same key if this system has one, else
 *     in the system collection "Import";
 *  4. if the package only names the image (no file) and this system does not have it, it is "missing" and the
 *     references to it are removed.
 */
final class MediaPlanner
{
    public function __construct(
        private readonly MediaTarget $target,
        private readonly TransferMappings $mappings,
    ) {
    }

    /**
     * @param array<int, list<string>> $usage UUIDs of the articles per image id on the source system
     *
     * @return list<PlanMedium>
     */
    public function plan(Package $package, string $sourceInstance, array $usage): array
    {
        $lookup = [];
        foreach ($package->manifest->references->media as $row) {
            $lookup[(int) $row['id']] = $row;
        }

        $ids = array_keys($usage);
        sort($ids, \SORT_NUMERIC);

        $media = [];
        foreach ($ids as $id) {
            $media[] = $this->planOne($id, $package->media[$id] ?? null, $lookup[$id] ?? null, $sourceInstance, $usage[$id]);
        }

        return $media;
    }

    /**
     * @param ?array<string, mixed> $row   lookup row of the image
     * @param list<string>          $usedBy
     */
    private function planOne(int $id, ?MediaEntry $entry, ?array $row, string $sourceInstance, array $usedBy): PlanMedium
    {
        $filename = $entry?->meta->filename ?? (null !== $row ? (string) $row['filename'] : null);
        $size = $entry?->meta->size ?? (null !== $row ? (int) $row['size'] : null);
        $sha256 = $entry?->meta->sha256 ?? (null !== $row ? (string) $row['sha256'] : null);
        $hasFile = null !== $entry;

        $make = static fn (string $action, ?int $targetId = null, ?string $reason = null, ?array $collection = null): PlanMedium => new PlanMedium(
            $id,
            $filename,
            $size,
            $sha256,
            $hasFile,
            $action,
            $targetId,
            $reason,
            $collection,
            $usedBy,
        );

        if (null === $sha256 || null === $filename || null === $size) {
            return $make(PlanMedium::MISSING);
        }

        $mapping = $this->mappings->findMapping($sourceInstance, 'media', (string) $id);
        if (null !== $mapping && ctype_digit($mapping->getTargetId()) && $this->target->exists((int) $mapping->getTargetId())) {
            $targetId = (int) $mapping->getTargetId();
            if ($this->target->sha256($targetId) === $sha256 || !$hasFile) {
                return $make(PlanMedium::REUSE, $targetId, PlanMedium::REASON_MAPPING);
            }

            return $make(PlanMedium::NEW_VERSION, $targetId, PlanMedium::REASON_MAPPING);
        }

        foreach ($this->target->candidates($filename, $size) as $candidate) {
            if ($this->target->sha256($candidate) === $sha256) {
                return $make(PlanMedium::REUSE, $candidate, PlanMedium::REASON_HASH);
            }
        }

        if (!$hasFile) {
            return $make(PlanMedium::MISSING);
        }

        return $make(PlanMedium::CREATE, null, null, $this->collectionFor($entry));
    }

    /**
     * @return array{type: string, id?: int, key?: string}
     */
    private function collectionFor(MediaEntry $entry): array
    {
        $key = $entry->meta->collection['key'];
        if (null !== $key && '' !== $key) {
            $id = $this->target->collectionIdByKey($key);
            if (null !== $id) {
                return ['type' => 'collection', 'id' => $id];
            }
        }

        return ['type' => 'system', 'key' => SuluContentTransferExtension::SYSTEM_COLLECTION_KEY];
    }

    /**
     * What the images mean for the references to them.
     *
     * @param list<PlanMedium> $media
     *
     * @return list<PlanResolution>
     */
    public static function resolutions(array $media): array
    {
        $resolutions = [];
        foreach ($media as $medium) {
            $resolutions[] = match ($medium->action) {
                PlanMedium::REUSE, PlanMedium::NEW_VERSION => new PlanResolution('media', $medium->sourceId, PlanResolution::MAPPED, $medium->targetId),
                PlanMedium::CREATE => new PlanResolution('media', $medium->sourceId, PlanResolution::PLANNED, null, 'media:'.$medium->sourceId),
                default => new PlanResolution('media', $medium->sourceId, PlanResolution::REMOVED),
            };
        }

        return $resolutions;
    }
}
