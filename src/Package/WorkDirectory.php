<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Package;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;

/**
 * The work directory (sulu_content_transfer.work_dir) with one subdirectory per unpacked package or temporary
 * file set. A subdirectory is addressed by a random token, never by a name taken from a package.
 */
final class WorkDirectory
{
    private const TOKEN_PATTERN = '/^[0-9a-f]{32}\z/';

    public function __construct(private readonly string $baseDir)
    {
    }

    /**
     * Creates an empty subdirectory and returns its token.
     */
    public function create(): string
    {
        $base = $this->ensureBase();
        $token = bin2hex(random_bytes(16));

        if (!@mkdir($base.\DIRECTORY_SEPARATOR.$token, 0700) && !is_dir($base.\DIRECTORY_SEPARATOR.$token)) {
            throw new ContentTransferException('The work directory for the package could not be created.', 'workdir.not_writable');
        }

        return $token;
    }

    public static function isValidToken(string $token): bool
    {
        return 1 === preg_match(self::TOKEN_PATTERN, $token);
    }

    /**
     * Absolute path of the subdirectory of a token (which must exist).
     */
    public function path(string $token): string
    {
        if (!self::isValidToken($token)) {
            throw new \InvalidArgumentException('Invalid work directory token.');
        }

        $path = $this->ensureBase().\DIRECTORY_SEPARATOR.$token;
        if (!is_dir($path)) {
            throw new ContentTransferException('The work directory of the package does not exist (any more).', 'workdir.missing');
        }

        return $path;
    }

    /**
     * Deletes the subdirectory of a token with everything in it. Symbolic links are removed, never followed.
     */
    public function remove(string $token): void
    {
        if (!self::isValidToken($token)) {
            return;
        }

        $path = $this->baseDir.\DIRECTORY_SEPARATOR.$token;
        if (!is_dir($path) || is_link($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }

    /**
     * Deletes subdirectories that were last changed more than the given number of seconds ago.
     *
     * @return int number of removed subdirectories
     */
    public function purgeOlderThan(int $seconds, ?int $now = null): int
    {
        if (!is_dir($this->baseDir)) {
            return 0;
        }

        $threshold = ($now ?? time()) - $seconds;
        $removed = 0;
        foreach (new \DirectoryIterator($this->baseDir) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink() || !self::isValidToken($entry->getFilename())) {
                continue;
            }
            if ($entry->getMTime() < $threshold) {
                $this->remove($entry->getFilename());
                ++$removed;
            }
        }

        return $removed;
    }

    private function ensureBase(): string
    {
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0700, true) && !is_dir($this->baseDir)) {
            throw new ContentTransferException('The work directory could not be created.', 'workdir.not_writable');
        }

        $real = realpath($this->baseDir);
        if (false === $real || !is_writable($real)) {
            throw new ContentTransferException('The work directory is not writable.', 'workdir.not_writable');
        }

        return $real;
    }
}
