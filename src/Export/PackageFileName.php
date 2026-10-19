<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Manuxi\SuluContentTransferBundle\Package\PackagePath;

/**
 * Turns the original file name of a medium into a name the package accepts (see {@see PackagePath::isSafeFileName()}).
 * The original name stays in meta.json; only the name inside the ZIP changes.
 */
final class PackageFileName
{
    private const MAX_BYTES = 255;

    private function __construct()
    {
    }

    public static function sanitize(string $name, int $mediaId): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        }

        if (PackagePath::isSafeFileName($name)) {
            return $name;
        }

        // slashes and other separators would hide the real file name: keep only what follows the last one
        $base = (string) preg_replace('~^.*[\\\\/]~su', '', $name);
        $clean = (string) preg_replace('/[\x00-\x1f\x7f\\\\\/:*?"<>|]/u', '_', $base);
        $clean = trim($clean, " .\t");
        $clean = ltrim($clean, '.');

        $extension = pathinfo($clean, \PATHINFO_EXTENSION);
        $stem = '' === $extension ? $clean : substr($clean, 0, -(\strlen($extension) + 1));
        $stem = rtrim($stem, ' .');

        // a device name such as "nul.jpg" or the reserved "meta.json" gets a prefix
        $candidate = self::join($stem, $extension);
        if ('' === $stem || !PackagePath::isSafeFileName($candidate)) {
            $stem = 'file-'.$mediaId.('' === $stem ? '' : '-'.$stem);
            $candidate = self::join($stem, $extension);
        }

        if (\strlen($candidate) > self::MAX_BYTES) {
            $room = self::MAX_BYTES - ('' === $extension ? 0 : \strlen($extension) + 1);
            $stem = rtrim(self::truncateUtf8($stem, max(1, $room)), ' .');
            $candidate = self::join($stem, $extension);
        }

        return PackagePath::isSafeFileName($candidate) ? $candidate : 'file-'.$mediaId;
    }

    private static function join(string $stem, string $extension): string
    {
        return '' === $extension ? $stem : $stem.'.'.$extension;
    }

    private static function truncateUtf8(string $text, int $bytes): string
    {
        if (\strlen($text) <= $bytes) {
            return $text;
        }

        $cut = substr($text, 0, $bytes);
        while ('' !== $cut && !mb_check_encoding($cut, 'UTF-8')) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}
