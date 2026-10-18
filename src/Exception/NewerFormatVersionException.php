<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Exception;

/**
 * The package was written by a newer version of the bundle than the one reading it.
 */
final class NewerFormatVersionException extends InvalidPackageException
{
    public const REASON = 'package.newer_version';

    public function __construct(private readonly int $packageVersion, private readonly int $supportedVersion)
    {
        parent::__construct(\sprintf(
            'The package comes from a newer version of the bundle (format version %d, this version reads up to %d). Please update the bundle on this system.',
            $packageVersion,
            $supportedVersion,
        ), self::REASON);
    }

    public function getPackageVersion(): int
    {
        return $this->packageVersion;
    }

    public function getSupportedVersion(): int
    {
        return $this->supportedVersion;
    }
}
