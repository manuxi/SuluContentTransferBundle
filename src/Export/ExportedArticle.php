<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

/**
 * What the export did with one article, language by language.
 */
final class ExportedArticle
{
    public const STATUS_LIVE = 'live';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_DRAFT_NOT_PUBLISHED = 'draft (not published)';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array<string, string> $locales language → one of the STATUS_* constants
     * @param list<string>          $skipped languages that were asked for but the article does not have
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $title,
        public readonly array $locales,
        public readonly array $skipped = [],
    ) {
    }
}
