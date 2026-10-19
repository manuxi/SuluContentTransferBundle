<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

/**
 * What the import analysis asks this system about the things the articles point to.
 */
interface ReferenceTarget
{
    public function page(string $uuid): ?TargetPage;

    /**
     * The page with this address in this language.
     */
    public function pageByUrl(string $locale, string $url): ?TargetPage;

    /**
     * Whether a resource exists. Used for snippets and articles (UUID) and for the kinds the package has no lookup
     * data for: "accounts", "collections", "target_groups" (id).
     */
    public function exists(string $resourceKey, int|string $id): bool;

    public function categoryByKey(string $key): ?int;

    /**
     * A category directly below the given parent (null: top level) that has one of the names in any language
     * (compared without regard to case).
     *
     * @param array<string, string> $names
     */
    public function categoryByNames(array $names, ?int $parentId): ?int;

    public function tagByName(string $name): ?int;

    public function contactByEmail(string $email): ?int;

    /**
     * @return list<int>
     */
    public function contactsByName(?string $firstName, ?string $lastName): array;
}
