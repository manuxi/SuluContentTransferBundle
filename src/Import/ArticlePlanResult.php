<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Manuxi\SuluContentTransferBundle\Import\Plan\Notice;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;

/**
 * An article of the package, planned, with the remarks about it.
 */
final class ArticlePlanResult
{
    /**
     * @param list<Notice> $notices
     */
    public function __construct(
        public readonly PlanArticle $article,
        public readonly array $notices,
    ) {
    }
}
