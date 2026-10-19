<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Exception;

/**
 * The user may not see (a language of) a selected article.
 */
class ExportDeniedException extends ContentTransferException
{
    public const REASON = 'export.denied';

    /**
     * @param list<string> $uuids the articles that cannot be exported
     */
    public function __construct(public readonly array $uuids)
    {
        parent::__construct(
            \sprintf('You do not have the permission to export: %s.', implode(', ', $uuids)),
            self::REASON,
        );
    }
}
