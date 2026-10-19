<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Manuxi\SuluContentTransferBundle\Format\ReferenceLookup;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanCategory;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Import\Target\ReferenceTarget;
use Manuxi\SuluContentTransferBundle\Repository\TransferMappings;

/**
 * Finds out what each reference of the package (to pages, snippets, articles, categories, tags, contacts and the kinds
 * without lookup data) points to on this system. Answers are remembered, so the plan gets one entry per target, and
 * what has to be created (categories with their missing parents, tags) is collected on the way.
 *
 * Rules (Plan E9):
 *  - pages: same UUID, then the address in one of the languages of the lookup data;
 *  - snippets, articles: same UUID or the mapping of an earlier import; articles of the package are "package";
 *  - categories: by key, else by name below the parent found before; what is missing is created together with its
 *    parents that are missing as well;
 *  - tags: by name, what is missing is created. The tag filters of smart content hold names, not ids ("tag_names");
 *  - contacts: by e-mail address, then by first and last name (when it is unambiguous);
 *  - accounts, collections, target groups: the package has no lookup data for them. The same id on this system is kept
 *    and reported as "unchecked", otherwise the reference is removed;
 *  - other kinds: nothing can be checked, the id is kept and reported as "unchecked".
 */
final class ReferenceResolver
{
    /** Kinds the package has no lookup data for but that can be looked up by id on this system. */
    public const KINDS_BY_ID = ['accounts', 'collections', 'target_groups'];

    /** @var array<string, PlanResolution> */
    private array $resolutions = [];

    /** @var array<string, PlanCategory> */
    private array $categories = [];

    /** @var array<string, true> */
    private array $tags = [];

    /**
     * @param array<string, true> $packageArticles UUIDs of the articles of the package
     */
    public function __construct(
        private readonly ReferenceTarget $target,
        private readonly TransferMappings $mappings,
        private readonly string $sourceInstance,
        private readonly ReferenceLookup $lookup,
        private readonly array $packageArticles,
    ) {
    }

    public function resolve(string $kind, int|string $id): PlanResolution
    {
        if ('tags' === $kind && \is_string($id)) {
            $kind = 'tag_names';
        }

        $key = PlanResolution::key($kind, $id);
        if (isset($this->resolutions[$key])) {
            return $this->resolutions[$key];
        }

        return $this->resolutions[$key] = match ($kind) {
            'pages' => $this->page((string) $id),
            'articles' => $this->article((string) $id),
            'snippets' => $this->snippet((string) $id),
            'categories' => $this->category($id),
            'tags' => $this->tag($id),
            'tag_names' => $this->tagName((string) $id),
            'contacts' => $this->contact($id),
            default => $this->byId($kind, $id),
        };
    }

    /**
     * Adds a resolution that was worked out elsewhere (the images).
     */
    public function add(PlanResolution $resolution): void
    {
        $this->resolutions[PlanResolution::key($resolution->kind, $resolution->sourceId)] = $resolution;
    }

    /**
     * Makes sure a tag name is created if this system does not have it (tags of new images).
     */
    public function planTag(string $name): void
    {
        $this->tagName($name);
    }

    /**
     * @return list<PlanResolution>
     */
    public function resolutions(): array
    {
        return array_values($this->resolutions);
    }

    /**
     * @return list<PlanCategory>
     */
    public function categories(): array
    {
        return array_values($this->categories);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $names = array_keys($this->tags);
        sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * Readable name of a target for messages.
     */
    public function describe(string $kind, int|string $id): string
    {
        $text = match ($kind) {
            'pages' => $this->describePage((string) $id),
            'articles' => $this->describeArticle((string) $id),
            'categories' => $this->describeCategory($id),
            'tags' => $this->row($this->lookup->tags, 'id', $id)['name'] ?? null,
            'contacts' => $this->describeContact($id),
            'snippets' => $this->describeSnippet((string) $id),
            default => null,
        };

        return \sprintf('%s "%s"', $this->kindLabel($kind), $text ?? (string) $id);
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'pages' => 'page',
            'articles' => 'article',
            'snippets' => 'snippet',
            'categories' => 'category',
            'tags', 'tag_names' => 'tag',
            'contacts' => 'contact',
            'accounts' => 'organization',
            'collections' => 'media folder',
            'target_groups' => 'target group',
            'media' => 'image',
            default => $kind,
        };
    }

    private function page(string $uuid): PlanResolution
    {
        if (null !== $this->target->page($uuid)) {
            return new PlanResolution('pages', $uuid, PlanResolution::MAPPED, $uuid);
        }

        $row = $this->row($this->lookup->pages, 'uuid', $uuid);
        foreach ($row['urls'] ?? [] as $locale => $url) {
            $page = $this->target->pageByUrl((string) $locale, (string) $url);
            if (null !== $page) {
                return new PlanResolution('pages', $uuid, PlanResolution::MAPPED, $page->uuid);
            }
        }

        return new PlanResolution('pages', $uuid, PlanResolution::REMOVED);
    }

    private function article(string $uuid): PlanResolution
    {
        if (isset($this->packageArticles[$uuid])) {
            return new PlanResolution('articles', $uuid, PlanResolution::PACKAGE);
        }

        return $this->byUuidOrMapping('articles', $uuid);
    }

    private function snippet(string $uuid): PlanResolution
    {
        return $this->byUuidOrMapping('snippets', $uuid);
    }

    private function byUuidOrMapping(string $kind, string $uuid): PlanResolution
    {
        $mapping = $this->mappings->findMapping($this->sourceInstance, $kind, $uuid);
        if (null !== $mapping && $this->target->exists($kind, $mapping->getTargetId())) {
            return new PlanResolution($kind, $uuid, PlanResolution::MAPPED, $mapping->getTargetId());
        }
        if ($this->target->exists($kind, $uuid)) {
            return new PlanResolution($kind, $uuid, PlanResolution::MAPPED, $uuid);
        }

        return new PlanResolution($kind, $uuid, PlanResolution::REMOVED);
    }

    private function category(int|string $id): PlanResolution
    {
        $row = $this->row($this->lookup->categories, 'id', $id);
        if (null === $row) {
            return new PlanResolution('categories', $id, PlanResolution::REMOVED);
        }

        if (null !== $row['key']) {
            $byKey = $this->target->categoryByKey($row['key']);
            if (null !== $byKey) {
                return new PlanResolution('categories', $id, PlanResolution::MAPPED, $byKey);
            }
        }

        $chain = [...$row['path'], ['key' => $row['key'], 'names' => $row['names']]];
        $parentId = null;
        $parentRef = null;
        $trail = [];
        $last = \count($chain) - 1;

        foreach ($chain as $index => $element) {
            $key = $element['key'];
            $names = $element['names'];
            $trail[] = $names;

            $found = null !== $key ? $this->target->categoryByKey($key) : null;
            if (null === $found && null === $parentRef) {
                $found = $this->target->categoryByNames($names, $parentId);
            }
            if (null !== $found) {
                $parentId = $found;
                $parentRef = null;

                continue;
            }

            $ref = null !== $key ? 'key:'.$key : 'path:'.substr(sha1(json_encode($trail, \JSON_THROW_ON_ERROR)), 0, 16);
            $sourceId = $index === $last ? (int) $row['id'] : null;
            $known = $this->categories[$ref] ?? null;
            if (null === $known || (null === $known->sourceId && null !== $sourceId)) {
                $this->categories[$ref] = new PlanCategory(
                    $ref,
                    $key,
                    $names,
                    null === $parentRef ? $parentId : null,
                    $parentRef,
                    $sourceId ?? $known?->sourceId,
                );
            }
            $parentRef = $ref;
        }

        if (null !== $parentRef) {
            return new PlanResolution('categories', $id, PlanResolution::PLANNED, null, $parentRef);
        }

        return new PlanResolution('categories', $id, PlanResolution::MAPPED, $parentId);
    }

    private function tag(int|string $id): PlanResolution
    {
        $row = $this->row($this->lookup->tags, 'id', $id);
        if (null === $row) {
            return new PlanResolution('tags', $id, PlanResolution::REMOVED);
        }

        $name = (string) $row['name'];
        $targetId = $this->target->tagByName($name);
        if (null !== $targetId) {
            return new PlanResolution('tags', $id, PlanResolution::MAPPED, $targetId);
        }

        $this->tags[$name] = true;

        return new PlanResolution('tags', $id, PlanResolution::PLANNED, null, 'tag:'.$name);
    }

    private function tagName(string $name): PlanResolution
    {
        $targetId = $this->target->tagByName($name);
        if (null !== $targetId) {
            return new PlanResolution('tag_names', $name, PlanResolution::MAPPED, $targetId);
        }

        $this->tags[$name] = true;

        return new PlanResolution('tag_names', $name, PlanResolution::PLANNED, null, 'tag:'.$name);
    }

    private function contact(int|string $id): PlanResolution
    {
        $row = $this->row($this->lookup->contacts, 'id', $id);
        if (null === $row) {
            return new PlanResolution('contacts', $id, PlanResolution::REMOVED);
        }

        $email = $row['email'];
        if (null !== $email && '' !== $email) {
            $found = $this->target->contactByEmail($email);
            if (null !== $found) {
                return new PlanResolution('contacts', $id, PlanResolution::MAPPED, $found);
            }
        }

        $matches = $this->target->contactsByName($row['firstName'], $row['lastName']);
        if (1 === \count($matches)) {
            return new PlanResolution('contacts', $id, PlanResolution::MAPPED, $matches[0]);
        }

        return new PlanResolution('contacts', $id, PlanResolution::REMOVED);
    }

    private function byId(string $kind, int|string $id): PlanResolution
    {
        if (!\in_array($kind, self::KINDS_BY_ID, true)) {
            return new PlanResolution($kind, $id, PlanResolution::UNCHECKED, $id);
        }

        if ($this->target->exists($kind, $id)) {
            return new PlanResolution($kind, $id, PlanResolution::UNCHECKED, $id);
        }

        return new PlanResolution($kind, $id, PlanResolution::REMOVED);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private function row(array $rows, string $field, int|string $value): ?array
    {
        foreach ($rows as $row) {
            if ((string) ($row[$field] ?? '') === (string) $value) {
                return $row;
            }
        }

        return null;
    }

    private function describePage(string $uuid): ?string
    {
        $row = $this->row($this->lookup->pages, 'uuid', $uuid);
        if (null === $row) {
            return null;
        }

        $url = reset($row['urls']);
        $title = reset($row['titles']);

        return false !== $title && false !== $url ? $title.' ('.$url.')' : (false !== $url ? $url : (false !== $title ? $title : null));
    }

    private function describeArticle(string $uuid): ?string
    {
        $row = $this->row($this->lookup->articles, 'uuid', $uuid);
        $title = null === $row ? false : reset($row['titles']);

        return false === $title ? null : $title;
    }

    private function describeSnippet(string $uuid): ?string
    {
        $row = $this->row($this->lookup->snippets, 'uuid', $uuid);
        $title = null === $row ? false : reset($row['titles']);

        return false === $title ? null : $title;
    }

    private function describeCategory(int|string $id): ?string
    {
        $row = $this->row($this->lookup->categories, 'id', $id);
        $name = null === $row ? false : reset($row['names']);

        return false === $name ? ($row['key'] ?? null) : $name;
    }

    private function describeContact(int|string $id): ?string
    {
        $row = $this->row($this->lookup->contacts, 'id', $id);
        if (null === $row) {
            return null;
        }

        $name = trim(($row['firstName'] ?? '').' '.($row['lastName'] ?? ''));

        return '' !== $name ? $name : ($row['email'] ?? null);
    }
}
