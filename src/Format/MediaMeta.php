<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;

/**
 * media/<id>/meta.json: everything about a medium except its file.
 */
final class MediaMeta
{
    private const TEXT_FIELDS = ['title', 'description', 'copyright', 'credits'];

    /** @var array<string, array{title: ?string, description: ?string, copyright: ?string, credits: ?string}> */
    public readonly array $meta;

    /** @var array<string, array<string, mixed>> */
    public readonly array $formatOptions;

    /** @var list<string> */
    public readonly array $tags;

    /** @var list<array{key: ?string, names: array<string, string>}> */
    public readonly array $categories;

    /** @var array<string, mixed> */
    public readonly array $properties;

    /** @var array{key: ?string, titlePath: list<array<string, string>>} */
    public readonly array $collection;

    /**
     * @param array<string, array<string, ?string>>                         $meta          texts per locale: title, description, copyright, credits
     * @param array{x: int, y: int}|null                                    $focusPoint
     * @param array<string, array<string, mixed>>                           $formatOptions crops per image format
     * @param list<string>                                                  $tags          tag names
     * @param list<array{key?: ?string, names?: array<string, string>}>     $categories
     * @param array<string, mixed>                                          $properties
     * @param array{key?: ?string, titlePath?: list<array<string, string>>} $collection    key of the collection and its title chain from the root, per locale
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $sha256,
        array $meta = [],
        public readonly ?array $focusPoint = null,
        array $formatOptions = [],
        array $tags = [],
        array $categories = [],
        array $properties = [],
        array $collection = [],
    ) {
        Data::assertSha256($sha256, 'media.sha256');
        if ($size < 0) {
            throw new InvalidPackageException('Invalid package: the size of a medium must not be negative.');
        }

        $normalizedMeta = [];
        foreach ($meta as $locale => $texts) {
            $normalizedMeta[(string) $locale] = [];
            foreach (self::TEXT_FIELDS as $field) {
                $normalizedMeta[(string) $locale][$field] = $texts[$field] ?? null;
            }
        }
        ksort($normalizedMeta, \SORT_STRING);
        $this->meta = $normalizedMeta;

        ksort($formatOptions, \SORT_STRING);
        $this->formatOptions = $formatOptions;

        $tags = array_values(array_unique($tags));
        sort($tags, \SORT_STRING);
        $this->tags = $tags;

        $normalizedCategories = [];
        foreach ($categories as $category) {
            $normalizedCategories[] = ['key' => $category['key'] ?? null, 'names' => $category['names'] ?? []];
        }
        usort($normalizedCategories, static fn (array $a, array $b): int => [$a['key'] ?? '', serialize($a['names'])] <=> [$b['key'] ?? '', serialize($b['names'])]);
        $this->categories = $normalizedCategories;

        ksort($properties, \SORT_STRING);
        $this->properties = $properties;

        $this->collection = [
            'key' => $collection['key'] ?? null,
            'titlePath' => $collection['titlePath'] ?? [],
        ];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'media';

        $meta = [];
        foreach (Data::map($data, 'meta', $path) as $locale => $texts) {
            if (!\is_array($texts)) {
                throw Data::error($path.'.meta', (string) $locale, 'an object');
            }
            foreach (self::TEXT_FIELDS as $field) {
                $meta[(string) $locale][$field] = Data::nullableString($texts, $field, $path.'.meta.'.$locale);
            }
        }

        $focusPoint = null;
        if (null !== ($data['focusPoint'] ?? null)) {
            $focus = Data::map($data, 'focusPoint', $path);
            $focusPoint = ['x' => Data::int($focus, 'x', $path.'.focusPoint'), 'y' => Data::int($focus, 'y', $path.'.focusPoint')];
        }

        $formatOptions = [];
        foreach (Data::map($data, 'formatOptions', $path) as $format => $options) {
            $formatOptions[(string) $format] = $options instanceof \stdClass ? [] : (\is_array($options) ? $options : throw Data::error($path.'.formatOptions', (string) $format, 'an object'));
        }

        $categories = [];
        foreach (Data::list($data, 'categories', $path) as $index => $category) {
            if (!\is_array($category)) {
                throw Data::error($path.'.categories', (string) $index, 'an object');
            }
            $categories[] = [
                'key' => Data::nullableString($category, 'key', $path.'.categories'),
                'names' => Data::stringMap($category, 'names', $path.'.categories'),
            ];
        }

        $collectionData = Data::map($data, 'collection', $path);
        $titlePath = [];
        foreach (Data::list($collectionData, 'titlePath', $path.'.collection') as $index => $titles) {
            $titlePath[] = Data::stringMap(['titles' => $titles], 'titles', $path.'.collection.titlePath.'.$index);
        }

        return new self(
            Data::string($data, 'filename', $path),
            Data::string($data, 'mimeType', $path),
            Data::int($data, 'size', $path),
            Data::string($data, 'sha256', $path),
            $meta,
            $focusPoint,
            $formatOptions,
            Data::stringList($data, 'tags', $path),
            $categories,
            Data::map($data, 'properties', $path),
            ['key' => Data::nullableString($collectionData, 'key', $path.'.collection'), 'titlePath' => $titlePath],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'meta' => Data::mapOut($this->meta),
            'focusPoint' => $this->focusPoint,
            'formatOptions' => Data::mapOut(array_map(static fn (array $options): array|\stdClass => Data::mapOut($options), $this->formatOptions)),
            'tags' => $this->tags,
            'categories' => array_map(static fn (array $category): array => [
                'key' => $category['key'],
                'names' => Data::mapOut($category['names']),
            ], $this->categories),
            'properties' => Data::mapOut($this->properties),
            'collection' => [
                'key' => $this->collection['key'],
                'titlePath' => array_map(static fn (array $titles): array|\stdClass => Data::mapOut($titles), $this->collection['titlePath']),
            ],
        ];
    }
}
