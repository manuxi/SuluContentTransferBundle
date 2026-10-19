<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

use Manuxi\SuluContentTransferBundle\Import\Target\ReferenceTarget;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetPage;

final class FakeReferenceTarget implements ReferenceTarget
{
    /** @var array<string, TargetPage> */
    public array $pages = [];

    /** @var array<string, true> "kind|id" */
    public array $existing = [];

    /** @var array<string, int> key => id */
    public array $categoryKeys = [];

    /** @var list<array{id: int, parent: int|null, names: list<string>}> */
    public array $categories = [];

    /** @var array<string, int> */
    public array $tags = [];

    /** @var array<string, int> */
    public array $contactEmails = [];

    /** @var list<array{id: int, first: string|null, last: string|null}> */
    public array $contacts = [];

    public function page(string $uuid): ?TargetPage
    {
        return $this->pages[$uuid] ?? null;
    }

    public function pageByUrl(string $locale, string $url): ?TargetPage
    {
        foreach ($this->pages as $page) {
            if (($page->urls[$locale] ?? null) === $url) {
                return $page;
            }
        }

        return null;
    }

    public function exists(string $resourceKey, int|string $id): bool
    {
        return isset($this->existing[$resourceKey.'|'.$id]);
    }

    public function categoryByKey(string $key): ?int
    {
        return $this->categoryKeys[$key] ?? null;
    }

    public function categoryByNames(array $names, ?int $parentId): ?int
    {
        $wanted = array_map('mb_strtolower', $names);
        foreach ($this->categories as $category) {
            if ($category['parent'] === $parentId && [] !== array_intersect($wanted, array_map('mb_strtolower', $category['names']))) {
                return $category['id'];
            }
        }

        return null;
    }

    public function tagByName(string $name): ?int
    {
        return $this->tags[$name] ?? null;
    }

    public function contactByEmail(string $email): ?int
    {
        return $this->contactEmails[$email] ?? null;
    }

    public function contactsByName(?string $firstName, ?string $lastName): array
    {
        $ids = [];
        foreach ($this->contacts as $contact) {
            if ($contact['first'] === $firstName && $contact['last'] === $lastName) {
                $ids[] = $contact['id'];
            }
        }

        return $ids;
    }
}
