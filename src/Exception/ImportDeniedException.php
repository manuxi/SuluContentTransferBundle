<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Exception;

/**
 * The user asked to import articles they may not write (create, edit, publish or add the images for).
 */
class ImportDeniedException extends ContentTransferException
{
    public const REASON = 'import.denied';

    /**
     * @param array<string, string> $reasons article UUID in the package => why it is denied (a DENIED_* code of ArticleImportAccess)
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(
            [] === $reasons
                ? 'You do not have the permission to import articles.'
                : \sprintf('You do not have the permission to import: %s.', implode(', ', array_keys($reasons))),
            self::REASON,
        );
    }
}
