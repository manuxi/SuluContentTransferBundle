<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test case with an empty temporary directory (and a work directory inside it) that is removed afterwards.
 */
abstract class TemporaryDirectoryTestCase extends TestCase
{
    protected string $directory;
    protected string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/sulu-content-transfer-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->workDir = $this->directory.'/work';
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    /**
     * Number of files (not directories) below a directory.
     */
    protected static function countFiles(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                ++$count;
            }
        }

        return $count;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
