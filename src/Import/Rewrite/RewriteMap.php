<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Rewrite;

/**
 * Answers, for a target of the package (kind and id on the source system), what the reference to it becomes here.
 */
interface RewriteMap
{
    /**
     * @param string     $kind     "media", "pages", "articles", "snippets", "categories", "tags", "contacts", ...
     * @param int|string $sourceId id on the source system
     */
    public function resolve(string $kind, int|string $sourceId): Rewrite;
}
