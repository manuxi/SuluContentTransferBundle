<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

/**
 * One place in an article that points to another resource: the importer rewrites exactly these places.
 */
final class ArticleReference
{
    /**
     * @param string     $locale      language of the dimension the reference was found in
     * @param string     $path        place in the content of that dimension, e.g. "blocks.0.image"
     * @param string     $resourceKey kind of the target, e.g. "media", "pages", "categories"
     * @param int|string $id          id of the target on the source system (UUID or number)
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $path,
        public readonly string $resourceKey,
        public readonly int|string $id,
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'article.references';

        return new self(
            Data::string($data, 'locale', $path),
            Data::string($data, 'path', $path),
            Data::string($data, 'resourceKey', $path),
            Data::id($data, 'id', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'path' => $this->path,
            'resourceKey' => $this->resourceKey,
            'id' => $this->id,
        ];
    }

    /**
     * Order used to keep the list of references of an article stable.
     */
    public static function compare(self $a, self $b): int
    {
        return [$a->locale, $a->path, $a->resourceKey, (string) $a->id] <=> [$b->locale, $b->path, $b->resourceKey, (string) $b->id];
    }
}
