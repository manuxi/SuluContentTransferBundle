<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;
use Manuxi\SuluContentTransferBundle\Exception\NewerFormatVersionException;

/**
 * manifest.json: what the package is, who made it, what it contains and the lookup data for everything that is
 * referenced but not part of it.
 */
final class Manifest
{
    public const FORMAT = 'sulu-content-transfer';
    public const FORMAT_VERSION = 1;

    /** @var list<ManifestItem> */
    public readonly array $items;

    /**
     * @param list<ManifestItem> $items
     */
    public function __construct(
        public readonly \DateTimeImmutable $createdAt,
        public readonly ManifestSource $source,
        public readonly ManifestOptions $options,
        array $items,
        public readonly ReferenceLookup $references,
    ) {
        usort($items, static fn (ManifestItem $a, ManifestItem $b): int => [$a->resourceKey, $a->id] <=> [$b->resourceKey, $b->id]);
        $this->items = $items;
    }

    /**
     * Checks "format" and "formatVersion" before anything else is read, so a package of a newer version is reported
     * as such even if its other fields have a layout this version does not know.
     *
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        self::assertSupportedVersion($data);

        $items = [];
        foreach (Data::list($data, 'items', 'manifest') as $index => $item) {
            if (!\is_array($item)) {
                throw Data::error('manifest.items', (string) $index, 'an object');
            }
            $items[] = ManifestItem::fromArray($item);
        }

        return new self(
            Data::dateTime($data, 'createdAt', 'manifest'),
            ManifestSource::fromArray(Data::map($data, 'source', 'manifest')),
            ManifestOptions::fromArray(Data::map($data, 'options', 'manifest')),
            $items,
            ReferenceLookup::fromArray(Data::map($data, 'references', 'manifest')),
        );
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function assertSupportedVersion(array $data): void
    {
        if (self::FORMAT !== ($data['format'] ?? null)) {
            throw new InvalidPackageException('The file is not a content transfer package (unknown "format" in manifest.json).');
        }

        $version = $data['formatVersion'] ?? null;
        if (!\is_int($version) || $version < 1) {
            throw Data::error('manifest', 'formatVersion', 'a positive integer');
        }
        if ($version > self::FORMAT_VERSION) {
            throw new NewerFormatVersionException($version, self::FORMAT_VERSION);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'formatVersion' => self::FORMAT_VERSION,
            'createdAt' => Data::dateOut($this->createdAt),
            'source' => $this->source->toArray(),
            'options' => $this->options->toArray(),
            'items' => array_map(static fn (ManifestItem $item): array => $item->toArray(), $this->items),
            'references' => $this->references->toArray(),
        ];
    }
}
