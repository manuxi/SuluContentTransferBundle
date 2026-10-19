<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

use Manuxi\SuluContentTransferBundle\Format\Data;

/**
 * What a reference of the package points to on this system. The import rewrites exactly according to this table.
 */
final class PlanResolution
{
    /** Found on this system: "targetId" is the id there. */
    public const MAPPED = 'mapped';
    /** Does not exist yet and is created by the import: "ref" names the entry in the plan (category, tag, medium). */
    public const PLANNED = 'planned';
    /** Cannot be resolved: the reference is removed from the content. */
    public const REMOVED = 'removed';
    /** Cannot be checked (see the kinds without lookup data): the id is kept as it is. */
    public const UNCHECKED = 'unchecked';
    /** The target is an article of the package; its id on this system depends on the action chosen for it. */
    public const PACKAGE = 'package';

    public const STATUSES = [self::MAPPED, self::PLANNED, self::REMOVED, self::UNCHECKED, self::PACKAGE];

    /**
     * @param string          $kind     kind of the target: "media", "pages", "articles", "snippets", "categories",
     *                                  "tags" (id), "tag_names" (smart content filters), "contacts", ...
     * @param int|string      $sourceId id on the source system
     * @param string          $status   one of the STATUSES
     * @param int|string|null $targetId id on this system (status "mapped", "unchecked")
     * @param ?string         $ref      entry of the plan that is created (status "planned")
     */
    public function __construct(
        public readonly string $kind,
        public readonly int|string $sourceId,
        public readonly string $status,
        public readonly int|string|null $targetId = null,
        public readonly ?string $ref = null,
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'plan.resolution';

        $status = Data::string($data, 'status', $path);
        if (!\in_array($status, self::STATUSES, true)) {
            throw Data::error($path, 'status', 'a known status');
        }

        $targetId = $data['targetId'] ?? null;
        if (null !== $targetId && !\is_int($targetId) && !\is_string($targetId)) {
            throw Data::error($path, 'targetId', 'an integer, a string or null');
        }

        return new self(
            Data::string($data, 'kind', $path),
            Data::id($data, 'sourceId', $path),
            $status,
            $targetId,
            Data::nullableString($data, 'ref', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'sourceId' => $this->sourceId,
            'status' => $this->status,
            'targetId' => $this->targetId,
            'ref' => $this->ref,
        ];
    }

    public static function key(string $kind, int|string $sourceId): string
    {
        return $kind."\0".(\is_int($sourceId) ? 'i' : 's').$sourceId;
    }
}
