<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

/**
 * Where the data for the reference lookup of the manifest comes from. Every method returns rows in the shape of
 * {@see \Manuxi\SuluContentTransferBundle\Format\ReferenceLookup}; ids that do not exist (any more) are simply left out.
 */
interface LookupSource
{
    /**
     * @param list<string> $uuids
     *
     * @return list<array<string, mixed>> uuid, webspace, urls (locale → address), titles (locale → title)
     */
    public function pages(array $uuids): array;

    /**
     * @param list<string> $uuids
     *
     * @return list<array<string, mixed>> uuid, templateKey, titles
     */
    public function snippets(array $uuids): array;

    /**
     * @param list<string> $uuids
     *
     * @return list<array<string, mixed>> uuid, templateKey, titles, urls
     */
    public function articles(array $uuids): array;

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>> id, key, names (locale → name), path (parents from the root: key, names)
     */
    public function categories(array $ids): array;

    /**
     * @param list<int>    $ids
     * @param list<string> $names
     *
     * @return list<array<string, mixed>> id, name
     */
    public function tags(array $ids, array $names): array;

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>> id, firstName, lastName, email
     */
    public function contacts(array $ids): array;
}
