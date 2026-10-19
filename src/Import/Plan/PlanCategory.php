<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

use Manuxi\SuluContentTransferBundle\Format\Data;

/**
 * A category the articles use that does not exist on this system and is created by the import, together with the
 * parents that are missing as well. The parent is either a category of this system ("parentId") or another entry
 * of this list ("parentRef"); without both the category is created on the top level.
 */
final class PlanCategory
{
    /** @var array<string, string> */
    public readonly array $names;

    /**
     * @param string                $ref       identifies the entry in the plan: "key:<key>" or, without a key, "path:<hash>"
     * @param array<string, string> $names     name per language
     * @param ?int                  $parentId  existing parent on this system
     * @param ?string               $parentRef parent that is created by this import
     * @param ?int                  $sourceId  id on the source system (null for a parent that only appears in a path)
     */
    public function __construct(
        public readonly string $ref,
        public readonly ?string $key,
        array $names,
        public readonly ?int $parentId = null,
        public readonly ?string $parentRef = null,
        public readonly ?int $sourceId = null,
    ) {
        ksort($names, \SORT_STRING);
        $this->names = $names;
    }

    /**
     * Readable name for reports, e.g. "Tutorials › Beginners" is built from the chain by the caller.
     */
    public function label(): string
    {
        foreach ($this->names as $name) {
            if ('' !== $name) {
                return $name;
            }
        }

        return $this->key ?? $this->ref;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'plan.category';

        return new self(
            Data::string($data, 'ref', $path),
            Data::nullableString($data, 'key', $path),
            Data::stringMap($data, 'names', $path),
            Data::nullableInt($data, 'parentId', $path),
            Data::nullableString($data, 'parentRef', $path),
            Data::nullableInt($data, 'sourceId', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'key' => $this->key,
            'names' => Data::mapOut($this->names),
            'parentId' => $this->parentId,
            'parentRef' => $this->parentRef,
            'sourceId' => $this->sourceId,
        ];
    }
}
