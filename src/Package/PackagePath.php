<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Package;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;

/**
 * The only paths a package may contain, and how to recognise them. Everything else is rejected by the reader and
 * never produced by the writer.
 *
 * <pre>
 * manifest.json
 * articles/<uuid>.json
 * media/<id>/meta.json
 * media/<id>/<file name>
 * </pre>
 */
final class PackagePath
{
    public const MANIFEST = 'manifest.json';
    public const MEDIA_META = 'meta.json';

    public const KIND_MANIFEST = 'manifest';
    public const KIND_ARTICLE = 'article';
    public const KIND_MEDIA_META = 'media_meta';
    public const KIND_MEDIA_FILE = 'media_file';
    public const KIND_DIRECTORY = 'directory';

    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private const MEDIA_ID_PATTERN = '[1-9][0-9]{0,17}';
    private const RESERVED_NAMES = '/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(\..*)?\z/is';

    private function __construct()
    {
    }

    public static function article(string $uuid): string
    {
        if (1 !== preg_match('/^'.self::UUID_PATTERN.'\z/', $uuid)) {
            throw new InvalidPackageException('Invalid package: an article id must be a lowercase UUID.');
        }

        return 'articles/'.$uuid.'.json';
    }

    public static function mediaMeta(int $id): string
    {
        self::assertMediaId($id);

        return 'media/'.$id.'/'.self::MEDIA_META;
    }

    public static function mediaFile(int $id, string $fileName): string
    {
        self::assertMediaId($id);
        if (!self::isSafeFileName($fileName)) {
            throw new InvalidPackageException('Invalid package: the file name of a medium is not allowed in a package.');
        }

        return 'media/'.$id.'/'.$fileName;
    }

    /**
     * Which kind of package file a path is, with the id it belongs to; null for every path that is not allowed.
     *
     * @return array{kind: string, id: string|null, file: string|null}|null
     */
    public static function classify(string $path): ?array
    {
        if (self::MANIFEST === $path) {
            return ['kind' => self::KIND_MANIFEST, 'id' => null, 'file' => null];
        }

        if (1 === preg_match('#^articles/('.self::UUID_PATTERN.')\.json\z#', $path, $matches)) {
            return ['kind' => self::KIND_ARTICLE, 'id' => $matches[1], 'file' => null];
        }

        if (\in_array($path, ['articles/', 'media/'], true)) {
            return ['kind' => self::KIND_DIRECTORY, 'id' => null, 'file' => null];
        }

        if (1 === preg_match('#^media/('.self::MEDIA_ID_PATTERN.')/\z#', $path, $matches)) {
            return ['kind' => self::KIND_DIRECTORY, 'id' => $matches[1], 'file' => null];
        }

        if (1 === preg_match('#^media/('.self::MEDIA_ID_PATTERN.')/([^/]+)\z#', $path, $matches)) {
            if (self::MEDIA_META === $matches[2]) {
                return ['kind' => self::KIND_MEDIA_META, 'id' => $matches[1], 'file' => $matches[2]];
            }
            if (self::isSafeFileName($matches[2])) {
                return ['kind' => self::KIND_MEDIA_FILE, 'id' => $matches[1], 'file' => $matches[2]];
            }
        }

        return null;
    }

    /**
     * A plain file name that is harmless on every file system: no separators, no control characters, no leading dot,
     * no characters Windows forbids, no device names, valid UTF-8, at most 255 bytes.
     */
    public static function isSafeFileName(string $name): bool
    {
        if ('' === $name || \strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8')) {
            return false;
        }
        if (1 === preg_match('/[\x00-\x1f\x7f\\\\\/:*?"<>|]/', $name)) {
            return false;
        }
        if ('.' === $name[0] || ' ' === $name[0] || str_ends_with($name, '.') || str_ends_with($name, ' ')) {
            return false;
        }
        if (1 === preg_match(self::RESERVED_NAMES, $name)) {
            return false;
        }

        return 0 !== strcasecmp($name, self::MEDIA_META);
    }

    /**
     * A short form of an untrusted path for error messages.
     */
    public static function describe(string $path): string
    {
        $path = preg_replace('/[^\x20-\x7E]/', '?', $path) ?? '?';

        return '"'.(\strlen($path) > 80 ? substr($path, 0, 77).'...' : $path).'"';
    }

    private static function assertMediaId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidPackageException('Invalid package: a media id must be a positive number.');
        }
    }
}
