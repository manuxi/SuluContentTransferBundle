<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

use Manuxi\SuluContentTransferBundle\Format\MediaMeta;
use Manuxi\SuluContentTransferBundle\Import\Write\MediaWriter;
use Manuxi\SuluContentTransferBundle\Import\Write\MediaWritten;

final class FakeMediaWriter implements MediaWriter
{
    public int $nextId = 700;

    public function __construct(private readonly WriteLog $log)
    {
    }

    public function systemCollectionId(string $key): int
    {
        return 99;
    }

    public function create(string $file, MediaMeta $meta, int $collectionId, array $tagIds, array $categoryIds, string $fallbackLocale): MediaWritten
    {
        $id = $this->nextId++;
        $this->log->add('media', (string) $id, [
            'file' => basename($file),
            'collection' => $collectionId,
            'tags' => $tagIds,
            'categories' => $categoryIds,
            'fallbackLocale' => $fallbackLocale,
            'content' => file_get_contents($file),
        ], 'media '.$id.' collection='.$collectionId);

        return new MediaWritten($id);
    }

    public function addVersion(int $mediaId, string $file, MediaMeta $meta, string $fallbackLocale): MediaWritten
    {
        $this->log->add('media_version', (string) $mediaId, basename($file), 'media_version '.$mediaId);

        return new MediaWritten($mediaId);
    }

    public function updateTexts(int $mediaId, MediaMeta $meta): void
    {
        $this->log->add('media_texts', (string) $mediaId, $meta->meta, 'media_texts '.$mediaId);
    }
}
