<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Report;

/**
 * What the import did with one image.
 */
final class MediaResult
{
    public const CREATED = 'created';
    public const REUSED = 'reused';
    public const NEW_VERSION = 'new_version';
    public const MISSING = 'missing';
    public const FAILED = 'failed';

    /**
     * @param self::CREATED|self::REUSED|self::NEW_VERSION|self::MISSING|self::FAILED $outcome
     * @param bool                                                                    $textsUpdated the titles, descriptions and copyright of an existing image were overwritten
     * @param list<string>                                                            $warnings     things that could not be taken over (a crop format this system does not know, ...)
     */
    public function __construct(
        public readonly int $sourceId,
        public readonly ?string $filename,
        public readonly string $outcome,
        public readonly ?int $targetId = null,
        public readonly bool $textsUpdated = false,
        public readonly array $warnings = [],
        public readonly ?string $message = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceId' => $this->sourceId,
            'filename' => $this->filename,
            'outcome' => $this->outcome,
            'targetId' => $this->targetId,
            'textsUpdated' => $this->textsUpdated,
            'warnings' => $this->warnings,
            'message' => $this->message,
        ];
    }
}
