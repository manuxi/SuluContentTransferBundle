<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Package;

/**
 * The configured limits for packages (sulu_content_transfer.limits).
 */
final class PackageLimits
{
    /**
     * Upper bound for a single JSON file inside a package, independent of the package limit.
     */
    public const MAX_JSON_FILE_SIZE = 32 * 1024 * 1024;

    public function __construct(
        public readonly int $maxPackageSize = 256 * 1024 * 1024,
        public readonly int $maxFiles = 2000,
    ) {
        if ($maxPackageSize < 1 || $maxFiles < 1) {
            throw new \InvalidArgumentException('The package limits must be positive.');
        }
    }
}
