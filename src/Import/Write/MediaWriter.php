<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

use Manuxi\SuluContentTransferBundle\Format\MediaMeta;

/**
 * Creates and updates images through Sulu's media manager.
 */
interface MediaWriter
{
    /**
     * The id of a folder that is a system collection.
     */
    public function systemCollectionId(string $key): int;

    /**
     * Creates an image from a file (origin "import"): texts per language, focus point, crops, tags and categories.
     *
     * @param list<int> $tagIds
     * @param list<int> $categoryIds
     */
    public function create(string $file, MediaMeta $meta, int $collectionId, array $tagIds, array $categoryIds, string $fallbackLocale): MediaWritten;

    /**
     * Adds the file as a new version of an existing image.
     */
    public function addVersion(int $mediaId, string $file, MediaMeta $meta, string $fallbackLocale): MediaWritten;

    /**
     * Overwrites titles, descriptions, copyright and credits of an existing image with the texts of the package.
     */
    public function updateTexts(int $mediaId, MediaMeta $meta): void;
}
