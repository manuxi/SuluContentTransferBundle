<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;

/**
 * Lookup data for everything an exported item points to but the package does not contain. The importing system uses
 * it to find the same thing on its side (by UUID, URL, key, name or e-mail address) and to report what is missing.
 *
 * Every entry is a map with the fields of its kind, see SPEC ("string", "?string", "int", "map" = locale => text,
 * "path" = chain of parent categories from the root, each with "key" and "names"). Unknown extra fields are kept so
 * that later versions can add some. Entries are sorted, so the same data always gives the same file.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class ReferenceLookup
{
    private const SPEC = [
        'pages' => ['uuid' => 'string', 'webspace' => '?string', 'urls' => 'map', 'titles' => 'map'],
        'snippets' => ['uuid' => 'string', 'templateKey' => '?string', 'titles' => 'map'],
        'articles' => ['uuid' => 'string', 'templateKey' => '?string', 'titles' => 'map', 'urls' => 'map'],
        'categories' => ['id' => 'int', 'key' => '?string', 'names' => 'map', 'path' => 'path'],
        'tags' => ['id' => 'int', 'name' => 'string'],
        'contacts' => ['id' => 'int', 'firstName' => '?string', 'lastName' => '?string', 'email' => '?string'],
        'media' => ['id' => 'int', 'filename' => 'string', 'size' => 'int', 'sha256' => 'sha256'],
    ];

    private const SORT_KEY = [
        'pages' => 'uuid',
        'snippets' => 'uuid',
        'articles' => 'uuid',
        'categories' => 'id',
        'tags' => 'id',
        'contacts' => 'id',
        'media' => 'id',
    ];

    /** @var list<Row> */
    public readonly array $pages;
    /** @var list<Row> */
    public readonly array $snippets;
    /** @var list<Row> */
    public readonly array $articles;
    /** @var list<Row> */
    public readonly array $categories;
    /** @var list<Row> */
    public readonly array $tags;
    /** @var list<Row> */
    public readonly array $contacts;
    /** @var list<Row> */
    public readonly array $media;

    /**
     * @param list<Row> $pages      uuid, webspace, urls (per locale), titles (per locale)
     * @param list<Row> $snippets   uuid, templateKey, titles
     * @param list<Row> $articles   articles that are not part of the package: uuid, templateKey, titles, urls
     * @param list<Row> $categories id, key, names (per locale), path (parents from the root: key, names)
     * @param list<Row> $tags       id, name
     * @param list<Row> $contacts   id, firstName, lastName, email
     * @param list<Row> $media      id, filename, size, sha256 (also when the files are not part of the package)
     */
    public function __construct(
        array $pages = [],
        array $snippets = [],
        array $articles = [],
        array $categories = [],
        array $tags = [],
        array $contacts = [],
        array $media = [],
    ) {
        $this->pages = self::normalize('pages', $pages);
        $this->snippets = self::normalize('snippets', $snippets);
        $this->articles = self::normalize('articles', $articles);
        $this->categories = self::normalize('categories', $categories);
        $this->tags = self::normalize('tags', $tags);
        $this->contacts = self::normalize('contacts', $contacts);
        $this->media = self::normalize('media', $media);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $lists = [];
        foreach (array_keys(self::SPEC) as $kind) {
            $rows = [];
            foreach (Data::list($data, $kind, 'references') as $index => $row) {
                if (!\is_array($row)) {
                    throw Data::error('references.'.$kind, (string) $index, 'an object');
                }
                $rows[] = $row;
            }
            $lists[$kind] = $rows;
        }

        return new self(
            $lists['pages'],
            $lists['snippets'],
            $lists['articles'],
            $lists['categories'],
            $lists['tags'],
            $lists['contacts'],
            $lists['media'],
        );
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function toArray(): array
    {
        $result = [];
        foreach (array_keys(self::SPEC) as $kind) {
            $result[$kind] = array_map(fn (array $row): array => $this->rowOut($kind, $row), $this->{$kind});
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function normalize(string $kind, array $rows): array
    {
        $spec = self::SPEC[$kind];
        $normalized = [];

        foreach ($rows as $index => $row) {
            $path = \sprintf('references.%s.%d', $kind, $index);
            foreach ($spec as $field => $type) {
                $row[$field] = self::checkField($row, $field, $type, $path);
            }
            ksort($row, \SORT_STRING);
            $normalized[] = $row;
        }

        $sortKey = self::SORT_KEY[$kind];
        usort($normalized, static fn (array $a, array $b): int => $a[$sortKey] <=> $b[$sortKey]);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function checkField(array $row, string $field, string $type, string $path): mixed
    {
        switch ($type) {
            case 'string':
                return Data::string($row, $field, $path);
            case '?string':
                return Data::nullableString($row, $field, $path);
            case 'int':
                return Data::int($row, $field, $path);
            case 'sha256':
                $hash = Data::string($row, $field, $path);
                Data::assertSha256($hash, $path.'.'.$field);

                return $hash;
            case 'map':
                return Data::stringMap($row, $field, $path);
            case 'path':
                $parents = [];
                foreach (Data::list($row, $field, $path) as $index => $parent) {
                    if (!\is_array($parent)) {
                        throw Data::error($path.'.'.$field, (string) $index, 'an object');
                    }
                    $parents[] = [
                        'key' => Data::nullableString($parent, 'key', $path.'.'.$field),
                        'names' => Data::stringMap($parent, 'names', $path.'.'.$field),
                    ];
                }

                return $parents;
        }

        throw new InvalidPackageException('Unknown field type "'.$type.'".');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function rowOut(string $kind, array $row): array
    {
        foreach (self::SPEC[$kind] as $field => $type) {
            if ('map' === $type) {
                $row[$field] = Data::mapOut($row[$field]);
            } elseif ('path' === $type) {
                $row[$field] = array_map(static fn (array $parent): array => [
                    'key' => $parent['key'],
                    'names' => Data::mapOut($parent['names']),
                ], $row[$field]);
            }
        }

        return $row;
    }
}
