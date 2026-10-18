<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

/**
 * One exported item as listed in the manifest, for a quick overview without opening the item files.
 */
final class ManifestItem
{
    public const RESOURCE_ARTICLES = 'articles';

    /** @var array<string, string> */
    public readonly array $titles;

    /**
     * @param array<string, string> $titles title per locale
     */
    public function __construct(
        public readonly string $resourceKey,
        public readonly string $id,
        public readonly ?string $templateKey,
        array $titles,
    ) {
        ksort($titles, \SORT_STRING);
        $this->titles = $titles;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'items';

        return new self(
            Data::string($data, 'resourceKey', $path),
            Data::string($data, 'id', $path),
            Data::nullableString($data, 'templateKey', $path),
            Data::stringMap($data, 'titles', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resourceKey' => $this->resourceKey,
            'id' => $this->id,
            'templateKey' => $this->templateKey,
            'titles' => Data::mapOut($this->titles),
        ];
    }
}
