<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Exception;

/**
 * The uploaded package is larger than the server accepts (PHP upload limits or the limit of the bundle).
 */
final class UploadTooLargeException extends ContentTransferException
{
    public const REASON = 'import.upload_too_large';

    /**
     * @param ?int $limit the limit that was exceeded in bytes, if known
     */
    public function __construct(public readonly ?int $limit)
    {
        parent::__construct(
            null !== $limit
                ? \sprintf('The package is larger than the %d bytes the server accepts for an upload. Import it on the console instead.', $limit)
                : 'The package is larger than the server accepts for an upload. Import it on the console instead.',
            self::REASON,
        );
    }
}
