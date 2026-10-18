<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Exception;

/**
 * A configured limit (package size, number of files) is exceeded.
 */
final class PackageLimitExceededException extends InvalidPackageException
{
    public const REASON_SIZE = 'package.too_large';
    public const REASON_FILES = 'package.too_many_files';

    public static function size(int $limit): self
    {
        return new self(\sprintf('The package exceeds the configured maximum size of %d bytes.', $limit), self::REASON_SIZE);
    }

    public static function files(int $limit): self
    {
        return new self(\sprintf('The package contains more than the configured maximum of %d files.', $limit), self::REASON_FILES);
    }
}
