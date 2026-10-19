<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

use Manuxi\SuluContentTransferBundle\Format\Data;

/**
 * An image the articles of the package use and what the import does with it on this system.
 */
final class PlanMedium
{
    /** The same file exists on this system and is used. */
    public const REUSE = 'reuse';
    /** The file is not on this system and is created from the package. */
    public const CREATE = 'create';
    /** The medium is known from an earlier import, but its file changed on the source: a new file version is added. */
    public const NEW_VERSION = 'new_version';
    /** The package only names the image and this system does not have it: the references are removed. */
    public const MISSING = 'missing';

    public const ACTIONS = [self::REUSE, self::CREATE, self::NEW_VERSION, self::MISSING];

    /** The medium was found through the mapping table. */
    public const REASON_MAPPING = 'mapping';
    /** A medium with the same name and size has the same SHA-256. */
    public const REASON_HASH = 'hash';

    /** @var list<string> */
    public readonly array $usedBy;

    /**
     * @param self::REUSE|self::CREATE|self::NEW_VERSION|self::MISSING $action
     * @param ?int                                                     $targetId   medium on this system (reuse, new version)
     * @param ?string                                                  $reason     how the medium on this system was found
     * @param bool                                                     $hasFile    whether the package contains the file
     * @param ?array{type: string, id?: int, key?: string}             $collection folder of a new medium: a collection of
     *                                                                             this system ("collection") or a system collection ("system")
     * @param list<string>                                             $usedBy     UUIDs of the articles that use the image
     */
    public function __construct(
        public readonly int $sourceId,
        public readonly ?string $filename,
        public readonly ?int $size,
        public readonly ?string $sha256,
        public readonly bool $hasFile,
        public readonly string $action,
        public readonly ?int $targetId = null,
        public readonly ?string $reason = null,
        public readonly ?array $collection = null,
        array $usedBy = [],
    ) {
        $usedBy = array_values(array_unique($usedBy));
        sort($usedBy, \SORT_STRING);
        $this->usedBy = $usedBy;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'plan.media';

        $action = Data::string($data, 'action', $path);
        if (!\in_array($action, self::ACTIONS, true)) {
            throw Data::error($path, 'action', 'a known action');
        }

        $collection = null;
        if (null !== ($data['collection'] ?? null)) {
            $raw = Data::map($data, 'collection', $path);
            $collection = ['type' => Data::string($raw, 'type', $path.'.collection')];
            if (null !== ($raw['id'] ?? null)) {
                $collection['id'] = Data::int($raw, 'id', $path.'.collection');
            }
            if (null !== ($raw['key'] ?? null)) {
                $collection['key'] = Data::string($raw, 'key', $path.'.collection');
            }
        }

        return new self(
            Data::int($data, 'sourceId', $path),
            Data::nullableString($data, 'filename', $path),
            Data::nullableInt($data, 'size', $path),
            Data::nullableString($data, 'sha256', $path),
            Data::bool($data, 'hasFile', $path),
            $action,
            Data::nullableInt($data, 'targetId', $path),
            Data::nullableString($data, 'reason', $path),
            $collection,
            Data::stringList($data, 'usedBy', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceId' => $this->sourceId,
            'filename' => $this->filename,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'hasFile' => $this->hasFile,
            'action' => $this->action,
            'targetId' => $this->targetId,
            'reason' => $this->reason,
            'collection' => $this->collection,
            'usedBy' => $this->usedBy,
        ];
    }
}
