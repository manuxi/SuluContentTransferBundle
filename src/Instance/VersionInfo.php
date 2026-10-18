<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Instance;

use Composer\InstalledVersions;

/**
 * The versions of Sulu and of this bundle on this system, written into the manifest of a package.
 */
final class VersionInfo
{
    public function __construct(
        private readonly string $suluVersion,
        private readonly string $bundleVersion,
    ) {
    }

    public static function detect(): self
    {
        return new self(
            self::versionOf('sulu/sulu'),
            self::versionOf('manuxi/sulu-content-transfer-bundle'),
        );
    }

    public function getSuluVersion(): string
    {
        return $this->suluVersion;
    }

    public function getBundleVersion(): string
    {
        return $this->bundleVersion;
    }

    private static function versionOf(string $package): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
            return 'unknown';
        }

        return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
    }
}
