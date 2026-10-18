<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Export;

use Manuxi\SuluContentTransferBundle\Export\LookupSource;

/**
 * A lookup source with a fixed set of rows that counts how often it was asked.
 */
final class FakeLookupSource implements LookupSource
{
    /** @var array<string, int> */
    public array $calls = [];

    /**
     * @param array<string, array<string, mixed>> $pages
     * @param array<string, array<string, mixed>> $snippets
     * @param array<string, array<string, mixed>> $articles
     * @param array<int, array<string, mixed>>    $categories
     * @param array<int, array<string, mixed>>    $tags
     * @param array<int, array<string, mixed>>    $contacts
     */
    public function __construct(
        private readonly array $pages = [],
        private readonly array $snippets = [],
        private readonly array $articles = [],
        private readonly array $categories = [],
        private readonly array $tags = [],
        private readonly array $contacts = [],
    ) {
    }

    public function pages(array $uuids): array
    {
        return $this->pick('pages', $this->pages, $uuids);
    }

    public function snippets(array $uuids): array
    {
        return $this->pick('snippets', $this->snippets, $uuids);
    }

    public function articles(array $uuids): array
    {
        return $this->pick('articles', $this->articles, $uuids);
    }

    public function categories(array $ids): array
    {
        return $this->pick('categories', $this->categories, $ids);
    }

    public function tags(array $ids, array $names): array
    {
        $this->calls['tags'] = ($this->calls['tags'] ?? 0) + 1;

        $rows = [];
        foreach ($this->tags as $id => $row) {
            if (\in_array($id, $ids, true) || \in_array($row['name'], $names, true)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function contacts(array $ids): array
    {
        return $this->pick('contacts', $this->contacts, $ids);
    }

    /**
     * @param array<int|string, array<string, mixed>> $rows
     * @param list<int|string>                        $keys
     *
     * @return list<array<string, mixed>>
     */
    private function pick(string $kind, array $rows, array $keys): array
    {
        $this->calls[$kind] = ($this->calls[$kind] ?? 0) + 1;

        $picked = [];
        foreach ($keys as $key) {
            if (isset($rows[$key])) {
                $picked[] = $rows[$key];
            }
        }

        return $picked;
    }
}
