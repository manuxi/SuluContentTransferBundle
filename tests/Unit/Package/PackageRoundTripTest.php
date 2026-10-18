<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Package;

use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Format\Manifest;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;

class PackageRoundTripTest extends TemporaryDirectoryTestCase
{
    private PackageWriter $writer;
    private PackageReader $reader;
    private WorkDirectory $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $limits = new PackageLimits();
        $this->workDirectory = new WorkDirectory($this->workDir);
        $this->writer = new PackageWriter($limits, $this->workDirectory);
        $this->reader = new PackageReader($limits, $this->workDirectory);
    }

    public function testWriterAndReaderRoundTripToFile(): void
    {
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);
        $file = $this->directory.'/out.sulu-transfer.zip';

        $this->writer->writeToFile($package, $file, $mediaFiles);
        $extracted = $this->reader->read($file);

        $this->assertEquals($package, $extracted->package);
        // identical as JSON, which is what ends up in the files (the order of keys inside the article data is not kept)
        $this->assertSame(Json::encode($package->manifest->toArray()), Json::encode($extracted->package->manifest->toArray()));
        $this->assertSame(
            Json::encode($package->articles[PackageFixtures::ARTICLE_1]->toArray()),
            Json::encode($extracted->package->articles[PackageFixtures::ARTICLE_1]->toArray()),
        );
        $this->assertSame(file_get_contents($mediaFiles[12]), file_get_contents($extracted->mediaFilePath($extracted->package->media[12])));
    }

    public function testWriterAndReaderRoundTripViaStream(): void
    {
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);

        $stream = fopen('php://temp', 'w+b');
        $this->writer->writeToStream($package, $stream, $mediaFiles);
        rewind($stream);
        $file = $this->directory.'/streamed.zip';
        file_put_contents($file, stream_get_contents($stream));
        fclose($stream);

        $this->assertEquals($package, $this->reader->read($file)->package);
        // the temporary file of the writer is gone again
        $this->assertSame([], glob($this->workDir.'/*/package.zip') ?: []);
    }

    public function testPackageWithoutMediaFilesRoundTrips(): void
    {
        ['package' => $package] = PackageFixtures::package($this->directory, false);
        $file = $this->directory.'/no-media.zip';

        $this->writer->writeToFile($package, $file);

        $this->assertEquals($package, $this->reader->read($file)->package);
    }

    public function testSameDataGivesSameBytes(): void
    {
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);
        $first = $this->directory.'/first.zip';
        $second = $this->directory.'/second.zip';

        $this->writer->writeToFile($package, $first, $mediaFiles);
        $this->writer->writeToFile($package, $second, $mediaFiles);

        $this->assertSame(hash_file('sha256', $first), hash_file('sha256', $second));
    }

    public function testPackageIsExtractedIntoItsOwnSubdirectoryOfTheWorkDirectory(): void
    {
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);
        $file = $this->directory.'/out.zip';
        $this->writer->writeToFile($package, $file, $mediaFiles);

        $extracted = $this->reader->read($file);

        $this->assertTrue(WorkDirectory::isValidToken($extracted->token));
        $this->assertSame(realpath($this->workDir).\DIRECTORY_SEPARATOR.$extracted->token, $extracted->directory);
        $this->assertFileExists($extracted->directory.'/manifest.json');
        $this->assertFileExists($extracted->directory.'/articles/'.PackageFixtures::ARTICLE_1.'.json');
        $this->assertFileExists($extracted->directory.'/media/12/meta.json');
        // 1 manifest + 1 article + meta and file of 1 medium
        $this->assertSame(4, self::countFiles($this->workDir));

        $this->workDirectory->remove($extracted->token);
        $this->assertSame(0, self::countFiles($this->workDir));
        $this->assertDirectoryDoesNotExist($extracted->directory);
    }

    public function testJsonIsStableSortedUtf8WithoutEscapedSlashesAndUnicode(): void
    {
        $json = Json::encode(['b' => 1, 'a' => ['z' => '/pfad/zu/Größe äöü €', 'y' => [3, 1, 2]], 'empty' => new \stdClass(), 'ratio' => 1.0]);

        $this->assertSame(<<<'JSON'
            {
                "a": {
                    "y": [
                        3,
                        1,
                        2
                    ],
                    "z": "/pfad/zu/Größe äöü €"
                },
                "b": 1,
                "empty": {},
                "ratio": 1.0
            }

            JSON, $json);
    }

    public function testEmptyObjectsAndListsKeepTheirKindOnTheRoundTrip(): void
    {
        $decoded = Json::decode('{"object": {}, "list": [], "nested": {"selection": {}}}');

        $this->assertInstanceOf(\stdClass::class, $decoded['object']);
        $this->assertSame([], $decoded['list']);
        $this->assertSame(
            '{"list":[],"nested":{"selection":{}},"object":{}}',
            json_encode(json_decode(Json::encode($decoded)), \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testArticleDataWithEmptyObjectSurvivesTheWholeRoundTrip(): void
    {
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);
        $file = $this->directory.'/out.zip';
        $this->writer->writeToFile($package, $file, $mediaFiles);

        $article = $this->reader->read($file)->package->articles[PackageFixtures::ARTICLE_1];

        $this->assertInstanceOf(\stdClass::class, $article->dimensions['de']->data['selection']);
        $this->assertSame(1.0, $article->dimensions['de']->data['ratio']);
        $this->assertSame('Erste Schritte: Übersicht & Größe', $article->dimensions['de']->data['title']);
    }

    public function testManifestAndArticleParseFromTheirOwnArrays(): void
    {
        $manifest = Manifest::fromArray(PackageFixtures::manifest());
        $this->assertSame(Manifest::FORMAT_VERSION, $manifest->toArray()['formatVersion']);

        $article = ArticleEntry::fromArray(PackageFixtures::articleArray());
        $this->assertSame(PackageFixtures::ARTICLE_1, $article->uuid);
        $this->assertTrue($article->dimensions['de']->published);
    }

    public function testWriterRefusesAMediumFileThatDoesNotMatchItsMetaData(): void
    {
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);
        file_put_contents($mediaFiles[12], 'something else entirely');

        $this->expectException(\Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException::class);
        $this->writer->writeToFile($package, $this->directory.'/out.zip', $mediaFiles);
    }

    public function testWriterRefusesAMissingMediumFile(): void
    {
        ['package' => $package] = PackageFixtures::package($this->directory);

        $this->expectException(\Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException::class);
        $this->writer->writeToFile($package, $this->directory.'/out.zip', []);
    }

    public function testWriterAppliesTheLimitsOfTheConfiguration(): void
    {
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);

        $tooManyFiles = new PackageWriter(new PackageLimits(maxFiles: 3), $this->workDirectory);
        try {
            $tooManyFiles->writeToFile($package, $this->directory.'/a.zip', $mediaFiles);
            $this->fail('The file limit was not applied.');
        } catch (\Manuxi\SuluContentTransferBundle\Exception\PackageLimitExceededException $exception) {
            $this->assertSame('package.too_many_files', $exception->getReason());
        }

        $tooLarge = new PackageWriter(new PackageLimits(maxPackageSize: 100), $this->workDirectory);
        try {
            $tooLarge->writeToFile($package, $this->directory.'/b.zip', $mediaFiles);
            $this->fail('The size limit was not applied.');
        } catch (\Manuxi\SuluContentTransferBundle\Exception\PackageLimitExceededException $exception) {
            $this->assertSame('package.too_large', $exception->getReason());
        }

        $this->assertFileDoesNotExist($this->directory.'/a.zip');
        $this->assertFileDoesNotExist($this->directory.'/b.zip');
        $this->assertSame([], glob($this->directory.'/*.part') ?: []);
    }

    public function testWritingIntoAMissingDirectoryIsReported(): void
    {
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);

        try {
            $this->writer->writeToFile($package, $this->directory.'/missing/out.zip', $mediaFiles);
            $this->fail('A missing directory must be reported.');
        } catch (\Manuxi\SuluContentTransferBundle\Exception\ContentTransferException $exception) {
            $this->assertSame('package.not_writable', $exception->getReason());
        }
    }
}
