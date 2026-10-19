<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

/**
 * Creates the categories and tags the articles need and this system does not have.
 */
interface TaxonomyWriter
{
    /**
     * @param ?string               $key      the key the category has on the source system
     * @param array<string, string> $names    name per language
     * @param ?int                  $parentId parent category (null: top level)
     *
     * @return int id of the new category
     */
    public function createCategory(?string $key, array $names, ?int $parentId): int;

    /**
     * @return int id of the tag with this name (created if it does not exist)
     */
    public function createTag(string $name): int;
}
