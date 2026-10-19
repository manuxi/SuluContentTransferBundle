<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Security;

/**
 * The languages the user may export for a selection of articles.
 */
final class ExportScope
{
    /**
     * @param list<string> $available languages the user may see in every selected article that has them
     * @param list<string> $locales   languages to export: the requested ones, or all available ones
     */
    public function __construct(
        public readonly array $available,
        public readonly array $locales,
    ) {
    }
}
