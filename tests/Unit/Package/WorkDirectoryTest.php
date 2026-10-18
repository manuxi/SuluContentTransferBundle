<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Package;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;

class WorkDirectoryTest extends TemporaryDirectoryTestCase
{
    public function testCreatesSubdirectoriesWithRandomTokensAndRemovesThemWithTheirContent(): void
    {
        $workDirectory = new WorkDirectory($this->workDir);

        $token = $workDirectory->create();
        $this->assertNotSame($token, $workDirectory->create());
        $path = $workDirectory->path($token);
        mkdir($path.'/sub');
        file_put_contents($path.'/sub/file.txt', 'x');

        $workDirectory->remove($token);

        $this->assertDirectoryDoesNotExist($path);
    }

    public function testRefusesTokensThatCouldPointSomewhereElse(): void
    {
        $workDirectory = new WorkDirectory($this->workDir);

        $this->assertFalse(WorkDirectory::isValidToken('../etc'));
        $this->assertFalse(WorkDirectory::isValidToken(str_repeat('A', 32)));
        $this->assertTrue(WorkDirectory::isValidToken(str_repeat('a', 32)));

        $this->expectException(\InvalidArgumentException::class);
        $workDirectory->path('../..');
    }

    public function testRemoveIgnoresInvalidTokens(): void
    {
        $outside = $this->directory.'/outside';
        mkdir($outside);

        (new WorkDirectory($this->workDir))->remove('../outside');

        $this->assertDirectoryExists($outside);
    }

    public function testUnknownTokenIsReported(): void
    {
        $this->expectException(ContentTransferException::class);
        (new WorkDirectory($this->workDir))->path(str_repeat('a', 32));
    }

    public function testPurgeRemovesOnlyOldTokenDirectories(): void
    {
        $workDirectory = new WorkDirectory($this->workDir);
        $old = $workDirectory->create();
        $new = $workDirectory->create();
        touch($workDirectory->path($old), time() - 2 * 86400);
        mkdir($this->workDir.'/not-a-token');
        touch($this->workDir.'/not-a-token', time() - 5 * 86400);

        $this->assertSame(1, $workDirectory->purgeOlderThan(86400));

        $this->assertDirectoryDoesNotExist($this->workDir.'/'.$old);
        $this->assertDirectoryExists($this->workDir.'/'.$new);
        $this->assertDirectoryExists($this->workDir.'/not-a-token');
    }
}
