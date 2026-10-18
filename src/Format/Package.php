<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;

/**
 * The content of a transfer package: manifest, articles and media. The constructor checks that the parts belong
 * together (every article listed in the manifest is there and vice versa, no duplicates), so a Package that exists
 * is consistent, whether it was built by the exporter or read from a file.
 */
final class Package
{
    /** @var array<string, ArticleEntry> */
    public readonly array $articles;

    /** @var array<int, MediaEntry> */
    public readonly array $media;

    /**
     * @param list<ArticleEntry> $articles
     * @param list<MediaEntry>   $media
     */
    public function __construct(
        public readonly Manifest $manifest,
        array $articles = [],
        array $media = [],
    ) {
        $byUuid = [];
        foreach ($articles as $article) {
            if (isset($byUuid[$article->uuid])) {
                throw new InvalidPackageException(\sprintf('Invalid package: article "%s" is contained twice.', $article->uuid));
            }
            $byUuid[$article->uuid] = $article;
        }
        ksort($byUuid, \SORT_STRING);
        $this->articles = $byUuid;

        $byId = [];
        foreach ($media as $entry) {
            if (isset($byId[$entry->id])) {
                throw new InvalidPackageException(\sprintf('Invalid package: medium %d is contained twice.', $entry->id));
            }
            $byId[$entry->id] = $entry;
        }
        ksort($byId, \SORT_NUMERIC);
        $this->media = $byId;

        $listed = [];
        foreach ($manifest->items as $item) {
            if (ManifestItem::RESOURCE_ARTICLES === $item->resourceKey) {
                $listed[$item->id] = true;
            }
        }
        foreach (array_keys($byUuid) as $uuid) {
            if (!isset($listed[$uuid])) {
                throw new InvalidPackageException(\sprintf('Invalid package: article "%s" is not listed in the manifest.', $uuid));
            }
        }
        foreach (array_keys($listed) as $uuid) {
            if (!isset($byUuid[$uuid])) {
                throw new InvalidPackageException(\sprintf('Invalid package: article "%s" is listed in the manifest but missing.', $uuid));
            }
        }
    }
}
