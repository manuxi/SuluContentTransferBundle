<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

/**
 * A page on this system with its address in every language.
 */
final class TargetPage
{
    /**
     * @param array<string, string> $urls address per language
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $webspace,
        public readonly array $urls = [],
    ) {
    }
}
