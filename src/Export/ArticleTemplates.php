<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

/**
 * Which template an article uses in which language. The template decides the group of an article and with it the
 * security context that governs who may see it.
 */
interface ArticleTemplates
{
    /**
     * Only articles and languages that exist appear in the result.
     *
     * @param list<string> $uuids
     *
     * @return array<string, array<string, string>> article UUID (lower case) → language → template key
     */
    public function templateKeys(array $uuids): array;
}
