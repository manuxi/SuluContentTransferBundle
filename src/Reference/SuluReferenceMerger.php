<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Reference;

use Manuxi\SuluContentTransferBundle\Format\ArticleReference;

/**
 * Adds the references Sulu itself collected (the ones that end up in the references table) to those found by the
 * {@see ReferenceScanner}.
 *
 * The paths Sulu reports belong to its resolved views, not to the admin form: they are translated to the path of
 * the value inside the admin form first. A reference only Sulu knows (typically a field type of a third-party bundle
 * the scanner does not know) is kept, so the importer can still rewrite it.
 */
final class SuluReferenceMerger
{
    /**
     * @param array<string, mixed>                                                             $data    admin form of the article in this language
     * @param list<ArticleReference>                                                           $scanned result of the scanner
     * @param list<array{resolver: string, path: string, resourceKey: string, id: int|string}> $sulu    references of Sulu
     *
     * @return array{references: list<ArticleReference>, onlyInSulu: list<ArticleReference>}
     */
    public function merge(string $locale, array $data, array $scanned, array $sulu): array
    {
        $known = [];
        foreach ($scanned as $reference) {
            $known[self::key($reference->path, $reference->resourceKey, $reference->id)] = $reference;
        }

        $onlyInSulu = [];
        foreach ($sulu as $row) {
            $resourceKey = $row['resourceKey'];
            $path = self::translatePath($row['resolver'], $row['path']);
            if ('users' === $resourceKey && 'author' === $path) {
                $resourceKey = ReferenceScanner::CONTACTS;
            }

            foreach (self::leafPaths($data, $path, $row['id']) as $leaf) {
                $key = self::key($leaf, $resourceKey, $row['id']);
                if (isset($known[$key])) {
                    continue;
                }

                $reference = new ArticleReference($locale, $leaf, $resourceKey, $row['id']);
                $known[$key] = $reference;
                $onlyInSulu[] = $reference;
            }
        }

        $references = array_values($known);
        usort($references, ArticleReference::compare(...));
        usort($onlyInSulu, ArticleReference::compare(...));

        return ['references' => $references, 'onlyInSulu' => $onlyInSulu];
    }

    /**
     * Path in the views of Sulu → path of the value in the admin form.
     */
    public static function translatePath(string $resolver, string $path): string
    {
        if ('settings' === $resolver && 'settings.author' === $path) {
            return 'author';
        }

        if ('excerpt' === $resolver) {
            return match ($path) {
                'excerpt.categories' => 'excerptCategories',
                'excerpt.tags' => 'excerptTags',
                'excerpt.audience_target_groups', 'excerpt.audienceTargetGroups' => 'excerptAudienceTargetGroups',
                default => $path,
            };
        }

        return $path;
    }

    /**
     * The places below $path of the admin form that hold the given id. If the form has nothing at the path
     * (a view that does not exist in the form), the path itself is returned.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function leafPaths(array $data, string $path, int|string $id): array
    {
        $current = $data;
        foreach ('' === $path ? [] : explode('.', $path) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return [$path];
            }
            $current = $current[$segment];
        }

        $found = [];
        self::collect($current, $path, (string) $id, $found, 0);

        return [] === $found ? [$path] : $found;
    }

    /**
     * @param list<string> $found
     */
    private static function collect(mixed $value, string $path, string $id, array &$found, int $depth): void
    {
        if (\is_array($value)) {
            if ($depth > 8) {
                return;
            }
            foreach ($value as $key => $item) {
                self::collect($item, '' === $path ? (string) $key : $path.'.'.$key, $id, $found, $depth + 1);
            }

            return;
        }

        if (\is_int($value) || \is_string($value)) {
            if ((string) $value === $id) {
                $found[] = $path;
            }
        }
    }

    private static function key(string $path, string $resourceKey, int|string $id): string
    {
        return $path.'|'.$resourceKey.'|'.$id;
    }
}
