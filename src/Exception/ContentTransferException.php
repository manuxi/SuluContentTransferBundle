<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Exception;

/**
 * Base class of all errors the bundle raises on purpose. The message is meant for logs and for the console; the
 * reason is a stable machine readable code that user interfaces can translate.
 */
class ContentTransferException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $reason = 'transfer.error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
