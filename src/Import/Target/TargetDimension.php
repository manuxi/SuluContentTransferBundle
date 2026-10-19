<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

/**
 * One version (language and stage) of an article on this system.
 */
final class TargetDimension
{
    /**
     * @param 'live'|'draft' $stage
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $stage,
        public readonly \DateTimeImmutable $changed,
        public readonly ?string $changedBy = null,
    ) {
    }
}
