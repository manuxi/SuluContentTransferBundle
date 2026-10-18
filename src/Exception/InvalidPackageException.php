<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Exception;

/**
 * The file is not a usable transfer package (broken, unexpected content, failed integrity check).
 */
class InvalidPackageException extends ContentTransferException
{
    public function __construct(string $message, string $reason = 'package.invalid', ?\Throwable $previous = null)
    {
        parent::__construct($message, $reason, $previous);
    }
}
