<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use Manuxi\SuluContentTransferBundle\Format\ReferenceLookup;
use Manuxi\SuluContentTransferBundle\Reference\ReferenceScanner;

/**
 * Builds the reference lookup of the manifest: what the importer needs to find the same page, category, tag, contact
 * or image on another system.
 */
class ReferenceLookupBuilder
{
    /**
     * Kinds the lookup has no rows for. The importer cannot match them on another system.
     */
    private const WITHOUT_LOOKUP = [ReferenceScanner::ACCOUNTS, ReferenceScanner::COLLECTIONS, ReferenceScanner::TARGET_GROUPS];

    public function __construct(private readonly LookupSource $source)
    {
    }

    /**
     * @param list<ArticleReference>     $references    all references of all exported articles
     * @param list<string>               $exportedUuids articles that are part of the package (they are not looked up)
     * @param list<array<string, mixed>> $mediaRows     id, filename, size, sha256 of every referenced image or file
     */
    public function build(array $references, array $exportedUuids, array $mediaRows): LookupResult
    {
        $ids = [];
        foreach ($references as $reference) {
            $ids[$reference->resourceKey][(string) $reference->id] = $reference->id;
        }

        $tagIds = [];
        $tagNames = [];
        foreach ($ids[ReferenceScanner::TAGS] ?? [] as $id) {
            if (\is_int($id) || (\is_string($id) && ctype_digit($id))) {
                $tagIds[] = (int) $id;
            } else {
                $tagNames[] = (string) $id;
            }
        }

        $exported = array_flip($exportedUuids);
        $pageUuids = self::strings($ids[ReferenceScanner::PAGES] ?? []);
        $snippetUuids = self::strings($ids[ReferenceScanner::SNIPPETS] ?? []);
        $articleUuids = array_values(array_filter(self::strings($ids[ReferenceScanner::ARTICLES] ?? []), static fn (string $uuid): bool => !isset($exported[$uuid])));
        $categoryIds = self::ints($ids[ReferenceScanner::CATEGORIES] ?? []);
        $contactIds = self::ints($ids[ReferenceScanner::CONTACTS] ?? []);

        $pages = [] === $pageUuids ? [] : $this->source->pages($pageUuids);
        $snippets = [] === $snippetUuids ? [] : $this->source->snippets($snippetUuids);
        $articles = [] === $articleUuids ? [] : $this->source->articles($articleUuids);
        $categories = [] === $categoryIds ? [] : $this->source->categories($categoryIds);
        $tags = [] === $tagIds && [] === $tagNames ? [] : $this->source->tags($tagIds, $tagNames);
        $contacts = [] === $contactIds ? [] : $this->source->contacts($contactIds);

        $mediaById = [];
        foreach ($mediaRows as $row) {
            $mediaById[(string) $row['id']] = $row;
        }

        $missing = [];
        $this->collectMissing($missing, ReferenceScanner::PAGES, $pageUuids, array_column($pages, 'uuid'));
        $this->collectMissing($missing, ReferenceScanner::SNIPPETS, $snippetUuids, array_column($snippets, 'uuid'));
        $this->collectMissing($missing, ReferenceScanner::ARTICLES, $articleUuids, array_column($articles, 'uuid'));
        $this->collectMissing($missing, ReferenceScanner::CATEGORIES, $categoryIds, array_column($categories, 'id'));
        $this->collectMissing($missing, ReferenceScanner::TAGS, $tagIds, array_column($tags, 'id'));
        $this->collectMissing($missing, ReferenceScanner::TAGS, $tagNames, array_column($tags, 'name'));
        $this->collectMissing($missing, ReferenceScanner::CONTACTS, $contactIds, array_column($contacts, 'id'));
        $this->collectMissing($missing, ReferenceScanner::MEDIA, self::ints($ids[ReferenceScanner::MEDIA] ?? []), array_keys($mediaById));

        $withoutLookup = [];
        foreach (self::WITHOUT_LOOKUP as $kind) {
            if (isset($ids[$kind])) {
                $withoutLookup[$kind] = array_values($ids[$kind]);
            }
        }

        return new LookupResult(
            new ReferenceLookup($pages, $snippets, $articles, $categories, $tags, $contacts, array_values($mediaById)),
            $missing,
            $withoutLookup,
        );
    }

    /**
     * @param array<string, list<int|string>> $missing
     * @param list<int|string>                $wanted
     * @param array<int|string, mixed>        $found
     */
    private function collectMissing(array &$missing, string $kind, array $wanted, array $found): void
    {
        $foundKeys = array_map('strval', $found);
        foreach ($wanted as $id) {
            if (!\in_array((string) $id, $foundKeys, true)) {
                $missing[$kind][] = $id;
            }
        }
    }

    /**
     * @param array<int|string, int|string> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_map('strval', $values));
    }

    /**
     * @param array<int|string, int|string> $values
     *
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        $ints = [];
        foreach ($values as $value) {
            if (\is_int($value) || ctype_digit($value)) {
                $ints[] = (int) $value;
            }
        }

        return $ints;
    }
}
