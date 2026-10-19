<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Doctrine\DBAL\Exception as DbalException;
use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;

/**
 * The text a failed unit of work (an article, an image, a tag) gets in the report. The report reaches the browser, so it
 * must not carry what only the server should know: the messages of the bundle itself are written for that and pass,
 * database errors are replaced (they quote the statement), and in every other message directories of the server are
 * blanked out. The full exception goes to the log.
 */
final class ImportFailure
{
    public const DATABASE_ERROR = 'A database error occurred. The details are in the log of the server.';

    private const MAX_LENGTH = 500;

    private function __construct()
    {
    }

    public static function describe(\Throwable $exception): string
    {
        if ($exception instanceof ContentTransferException) {
            return self::shorten($exception->getMessage());
        }

        for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof DbalException || $cause instanceof \PDOException) {
                return self::DATABASE_ERROR;
            }
        }

        return self::shorten(self::withoutPaths($exception->getMessage()));
    }

    /**
     * Replaces absolute paths (Windows drive letters, UNC paths, and Unix paths of at least two segments) with "…".
     */
    public static function withoutPaths(string $message): string
    {
        $patterns = [
            // C:\dir\file, C:/dir/file
            '#[A-Za-z]:[\\\\/][^\s"\'<>|*?]*(?<![.,:;)\]])#u',
            // \\server\share\file
            '#\\\\\\\\[^\s"\'<>|*?]+(?<![.,:;)\]])#u',
            // /var/www/project/file (a lone "/" or a URL path such as "/a" stays)
            '#(?<![\w:/.-])/(?:[\w.@+~-]+/)+[\w.@+~-]*(?<![.,:;)\]])#u',
        ];

        return preg_replace($patterns, '…', $message) ?? $message;
    }

    private static function shorten(string $message): string
    {
        return mb_strlen($message) > self::MAX_LENGTH ? mb_substr($message, 0, self::MAX_LENGTH - 1).'…' : $message;
    }
}
