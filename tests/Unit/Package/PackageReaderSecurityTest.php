<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Package;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;
use Manuxi\SuluContentTransferBundle\Exception\NewerFormatVersionException;
use Manuxi\SuluContentTransferBundle\Exception\PackageLimitExceededException;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;

/**
 * Every check of the reader against a package that was made to cause harm.
 */
class PackageReaderSecurityTest extends TemporaryDirectoryTestCase
{
    private const MEDIA_CONTENT = 'binary content';

    private WorkDirectory $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDirectory = new WorkDirectory($this->workDir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unexpectedEntries(): iterable
    {
        $uuid = PackageFixtures::ARTICLE_1;

        yield 'zip slip' => ['../evil.json'];
        yield 'zip slip below an allowed folder' => ['articles/../../evil.json'];
        yield 'zip slip inside media' => ['media/1/../../evil.jpg'];
        yield 'zip slip with backslashes' => ['articles\\..\\..\\evil.json'];
        yield 'absolute path' => ['/etc/evil.json'];
        yield 'absolute path to the manifest' => ['/manifest.json'];
        yield 'windows drive' => ['C:/evil.json'];
        yield 'windows drive with backslash' => ['C:\\evil.json'];
        yield 'current directory' => ['./evil.json'];
        yield 'foreign file in the root' => ['readme.txt'];
        yield 'foreign file with a php extension' => ['evil.php'];
        yield 'article that is no uuid' => ['articles/not-a-uuid.json'];
        yield 'article with an upper case uuid' => ['articles/'.strtoupper($uuid).'.json'];
        yield 'article with another extension' => ['articles/'.$uuid.'.php'];
        yield 'article with a sub folder' => ['articles/sub/'.$uuid.'.json'];
        yield 'article with a trailing path' => ['articles/'.$uuid.'.json/extra'];
        yield 'medium with a text as id' => ['media/abc/meta.json'];
        yield 'medium with the id zero' => ['media/0/meta.json'];
        yield 'medium with a leading zero' => ['media/01/meta.json'];
        yield 'medium directly in media' => ['media/picture.jpg'];
        yield 'medium with a sub folder' => ['media/1/sub/picture.jpg'];
        yield 'hidden file in a medium folder' => ['media/1/.htaccess'];
        yield 'device name in a medium folder' => ['media/1/nul.jpg'];
        yield 'file name with a colon' => ['media/1/stream:name.jpg'];
        yield 'file name ending with a dot' => ['media/1/picture.'];
        yield 'second meta file in other case' => ['media/1/META.JSON'];
        yield 'other folder' => ['other/1/picture.jpg'];
    }

    /**
     * @dataProvider unexpectedEntries
     */
    public function testUnexpectedEntryIsRefusedAndNothingIsUnpacked(string $name): void
    {
        $file = $this->zip([
            'manifest.json' => $this->manifestJson(),
            $name => 'evil',
        ]);

        try {
            $this->reader()->read($file);
            $this->fail('The entry '.$name.' was accepted.');
        } catch (InvalidPackageException $exception) {
            $this->assertSame('package.unexpected_entry', $exception->getReason());
        }

        $this->assertNothingUnpacked();
    }

    public function testZipSlipNeverWritesOutsideOfTheWorkDirectory(): void
    {
        $file = $this->zip([
            'manifest.json' => $this->manifestJson(),
            '../../evil.json' => 'evil',
            '../evil.json' => 'evil',
        ]);

        try {
            $this->reader()->read($file);
            $this->fail('The package was accepted.');
        } catch (InvalidPackageException $exception) {
            $this->assertSame('package.unexpected_entry', $exception->getReason());
        }

        foreach (['evil.json', 'work/evil.json', '../evil.json', '../../evil.json'] as $relative) {
            $this->assertFileDoesNotExist($this->directory.'/'.$relative);
        }
        $this->assertNothingUnpacked();
    }

    public function testPackageLargerThanTheLimitIsRefusedBeforeItIsOpened(): void
    {
        $file = $this->zip(['manifest.json' => $this->manifestJson()]);

        $this->assertLimitExceeded('package.too_large', $file, new PackageLimits(maxPackageSize: 50));
    }

    public function testZipBombWithAHugeUnpackedSizeIsRefused(): void
    {
        $file = $this->zip([
            'manifest.json' => $this->manifestJson(),
            'media/1/zeros.bin' => str_repeat("\0", 5_000_000),
        ]);
        $this->assertLessThan(20_000, filesize($file), 'the test archive must be small');

        $this->assertLimitExceeded('package.too_large', $file, new PackageLimits(maxPackageSize: 1_000_000));
    }

    public function testTooManyFilesAreRefused(): void
    {
        $file = $this->zip([
            'manifest.json' => $this->manifestJson(),
            'media/1/a.jpg' => 'a',
            'media/2/a.jpg' => 'a',
            'media/3/a.jpg' => 'a',
            'media/4/a.jpg' => 'a',
        ]);

        $this->assertLimitExceeded('package.too_many_files', $file, new PackageLimits(maxFiles: 4));
    }

    public function testPackageOfANewerFormatVersionIsRefusedWithAClearMessage(): void
    {
        // the layout of a newer version may differ in every other respect: the version is checked first
        $file = $this->zip([
            'manifest.json' => Json::encode(['format' => 'sulu-content-transfer', 'formatVersion' => 2, 'somethingNew' => ['a' => 1]]),
            'new-kind-of-file.dat' => 'x',
        ]);

        try {
            $this->reader()->read($file);
            $this->fail('The newer package was accepted.');
        } catch (NewerFormatVersionException $exception) {
            $this->assertStringContainsString('newer version of the bundle', $exception->getMessage());
            $this->assertSame('package.newer_version', $exception->getReason());
            $this->assertSame(2, $exception->getPackageVersion());
            $this->assertSame(1, $exception->getSupportedVersion());
        }

        $this->assertNothingUnpacked();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function brokenManifests(): iterable
    {
        yield 'other format' => [['format' => 'something-else']];
        yield 'no version' => [['formatVersion' => null]];
        yield 'version as text' => [['formatVersion' => '1']];
        yield 'version zero' => [['formatVersion' => 0]];
        yield 'no creation time' => [['createdAt' => 'yesterday']];
        yield 'no source' => [['source' => 'me']];
        yield 'unknown stage' => [['options' => ['locales' => ['de'], 'stage' => 'review', 'includeMediaFiles' => true]]];
        yield 'items not a list' => [['items' => ['a' => 1]]];
        yield 'bad hash in the lookup' => [['references' => ['pages' => [], 'snippets' => [], 'articles' => [], 'categories' => [], 'tags' => [], 'contacts' => [], 'media' => [['id' => 1, 'filename' => 'a.jpg', 'size' => 1, 'sha256' => 'nothex']]]]];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @dataProvider brokenManifests
     */
    public function testBrokenManifestIsRefused(array $overrides): void
    {
        $file = $this->zip(['manifest.json' => Json::encode(array_replace(PackageFixtures::manifest(['items' => []]), $overrides))]);

        $this->expectException(InvalidPackageException::class);
        try {
            $this->reader()->read($file);
        } finally {
            $this->assertNothingUnpacked();
        }
    }

    public function testPackageWithoutManifestIsRefused(): void
    {
        $file = $this->zip(['articles/'.PackageFixtures::ARTICLE_1.'.json' => Json::encode(PackageFixtures::articleArray())]);

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessage('no manifest.json');
        $this->reader()->read($file);
    }

    public function testFileThatIsNoZipIsRefused(): void
    {
        $file = $this->directory.'/not-a-zip.zip';
        file_put_contents($file, 'this is only text');

        $this->expectException(InvalidPackageException::class);
        $this->reader()->read($file);
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(InvalidPackageException::class);
        $this->reader()->read($this->directory.'/does-not-exist.zip');
    }

    public function testEncryptedEntryIsRefused(): void
    {
        $file = $this->zip(
            ['manifest.json' => $this->manifestJson(), 'media/1/secret.jpg' => 'content'],
            static function (\ZipArchive $zip): void {
                $zip->setPassword('secret');
                $zip->setEncryptionName('media/1/secret.jpg', \ZipArchive::EM_AES_256);
            },
        );

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessage('encrypted');
        $this->reader()->read($file);
    }

    public function testSymbolicLinkEntryIsRefused(): void
    {
        $file = $this->zip(
            ['manifest.json' => $this->manifestJson(), 'media/1/link.jpg' => '/etc/passwd'],
            static function (\ZipArchive $zip): void {
                $zip->setExternalAttributesName('media/1/link.jpg', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
            },
        );

        try {
            $this->reader()->read($file);
            $this->fail('The symbolic link was accepted.');
        } catch (InvalidPackageException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }
        $this->assertNothingUnpacked();
    }

    public function testEntriesThatDifferOnlyInCaseAreRefused(): void
    {
        $file = $this->zip([
            'manifest.json' => $this->manifestJson(),
            'media/1/Picture.jpg' => 'a',
            'media/1/picture.jpg' => 'b',
        ]);

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessage('twice');
        $this->reader()->read($file);
    }

    public function testMediumFileThatDoesNotMatchItsHashIsRefused(): void
    {
        $file = $this->zip($this->mediaEntries(content: 'tampered', declared: self::MEDIA_CONTENT));

        try {
            $this->reader()->read($file);
            $this->fail('The tampered medium was accepted.');
        } catch (InvalidPackageException $exception) {
            $this->assertSame('package.integrity', $exception->getReason());
        }
        $this->assertNothingUnpacked();
    }

    public function testArticleWithAnotherIdThanItsFileNameIsRefused(): void
    {
        $file = $this->zip([
            'manifest.json' => $this->manifestJson(),
            'articles/'.PackageFixtures::ARTICLE_1.'.json' => Json::encode(PackageFixtures::articleArray(PackageFixtures::ARTICLE_2)),
        ]);

        $this->expectException(InvalidPackageException::class);
        $this->reader()->read($file);
    }

    public function testArticleThatIsNotListedInTheManifestIsRefused(): void
    {
        $file = $this->zip([
            'manifest.json' => Json::encode(PackageFixtures::manifest(['items' => []])),
            'articles/'.PackageFixtures::ARTICLE_1.'.json' => Json::encode(PackageFixtures::articleArray()),
        ]);

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessage('not listed in the manifest');
        $this->reader()->read($file);
    }

    public function testArticleListedInTheManifestButMissingIsRefused(): void
    {
        $file = $this->zip(['manifest.json' => $this->manifestJson()]);

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessage('missing');
        $this->reader()->read($file);
    }

    public function testMediumWithoutFileOrWithoutMetaDataIsRefused(): void
    {
        $withoutFile = $this->mediaEntries();
        unset($withoutFile['media/12/Bild.jpg']);
        $withoutMeta = $this->mediaEntries();
        unset($withoutMeta['media/12/meta.json']);

        foreach ([$withoutFile, $withoutMeta] as $entries) {
            try {
                $this->reader()->read($this->zip($entries));
                $this->fail('The incomplete medium was accepted.');
            } catch (InvalidPackageException) {
                $this->assertNothingUnpacked();
            }
        }
    }

    public function testMediumWithTwoFilesIsRefused(): void
    {
        $entries = $this->mediaEntries();
        $entries['media/12/second.jpg'] = self::MEDIA_CONTENT;

        $this->expectException(InvalidPackageException::class);
        $this->reader()->read($this->zip($entries));
    }

    public function testArticleThatIsNoJsonIsRefusedAndCleanedUp(): void
    {
        $file = $this->zip([
            'manifest.json' => $this->manifestJson(),
            'articles/'.PackageFixtures::ARTICLE_1.'.json' => '{not json',
        ]);

        try {
            $this->reader()->read($file);
            $this->fail('The broken article was accepted.');
        } catch (InvalidPackageException $exception) {
            $this->assertStringContainsString('not valid JSON', $exception->getMessage());
        }
        $this->assertNothingUnpacked();
    }

    public function testErrorMessagesDoNotContainServerPaths(): void
    {
        $file = $this->zip(['manifest.json' => $this->manifestJson(), "bad\x01name/../x" => 'x']);

        try {
            $this->reader()->read($file);
            $this->fail('The entry was accepted.');
        } catch (InvalidPackageException $exception) {
            $this->assertStringNotContainsString($this->directory, $exception->getMessage());
            $this->assertStringNotContainsString("\x01", $exception->getMessage());
        }
    }

    private function reader(?PackageLimits $limits = null): PackageReader
    {
        return new PackageReader($limits ?? new PackageLimits(), $this->workDirectory);
    }

    private function manifestJson(): string
    {
        return Json::encode(PackageFixtures::manifest());
    }

    /**
     * A valid package with one article and (unlike the manifest suggests) one medium whose file content may be
     * different from what its meta data promises.
     *
     * @return array<string, string>
     */
    private function mediaEntries(string $content = self::MEDIA_CONTENT, string $declared = self::MEDIA_CONTENT): array
    {
        $article = PackageFixtures::articleArray();

        return [
            'manifest.json' => $this->manifestJson(),
            'articles/'.PackageFixtures::ARTICLE_1.'.json' => Json::encode($article),
            'media/12/meta.json' => Json::encode([
                'filename' => 'Bild.jpg',
                'mimeType' => 'image/jpeg',
                'size' => \strlen($declared),
                'sha256' => hash('sha256', $declared),
                'meta' => new \stdClass(),
                'focusPoint' => null,
                'formatOptions' => new \stdClass(),
                'tags' => [],
                'categories' => [],
                'properties' => new \stdClass(),
                'collection' => ['key' => null, 'titlePath' => []],
            ]),
            'media/12/Bild.jpg' => $content,
        ];
    }

    /**
     * @param array<string, string>              $entries    path => content, written exactly as given
     * @param (callable(\ZipArchive): void)|null $afterwards
     */
    private function zip(array $entries, ?callable $afterwards = null): string
    {
        $file = $this->directory.'/evil-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file, \ZipArchive::CREATE));
        foreach ($entries as $path => $content) {
            $this->assertTrue($zip->addFromString((string) $path, $content), 'could not add '.$path);
        }
        if (null !== $afterwards) {
            $afterwards($zip);
        }
        $this->assertTrue($zip->close());

        return $file;
    }

    private function assertLimitExceeded(string $reason, string $file, PackageLimits $limits): void
    {
        try {
            $this->reader($limits)->read($file);
            $this->fail('The limit was not applied.');
        } catch (PackageLimitExceededException $exception) {
            $this->assertSame($reason, $exception->getReason());
        }

        $this->assertNothingUnpacked();
    }

    private function assertNothingUnpacked(): void
    {
        $this->assertSame(0, self::countFiles($this->workDir), 'files were left in the work directory');
        $this->assertSame([], glob($this->workDir.'/*') ?: [], 'directories were left in the work directory');
    }
}
