<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Command;

use Manuxi\SuluContentTransferBundle\Command\ExportCommand;
use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Export\ArticleExporter;
use Manuxi\SuluContentTransferBundle\Export\ExportedArticle;
use Manuxi\SuluContentTransferBundle\Export\ExportRequest;
use Manuxi\SuluContentTransferBundle\Export\ExportResult;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Tester\CommandTester;

class ExportCommandTest extends TemporaryDirectoryTestCase
{
    /** @var MockObject&ArticleExporter */
    private $exporter;
    private ?ExportRequest $request = null;

    private function tester(?ExportResult $result = null, ?\Throwable $failure = null): CommandTester
    {
        $this->exporter = $this->createMock(ArticleExporter::class);
        $this->exporter->method('export')->willReturnCallback(function (ExportRequest $request) use ($result, $failure): ExportResult {
            $this->request = $request;
            if (null !== $failure) {
                throw $failure;
            }

            return $result ?? $this->exportResult();
        });

        $workDirectory = new WorkDirectory($this->workDir);
        $command = new ExportCommand($this->exporter, new PackageWriter(new PackageLimits(), $workDirectory));

        return new CommandTester($command);
    }

    private function exportResult(bool $withMedia = true): ExportResult
    {
        $fixture = PackageFixtures::package($this->directory, $withMedia);

        return new ExportResult(
            $fixture['package'],
            $fixture['mediaFiles'],
            [
                new ExportedArticle(PackageFixtures::ARTICLE_1, 'Erste Schritte', ['de' => ExportedArticle::STATUS_DRAFT_NOT_PUBLISHED, 'en' => ExportedArticle::STATUS_LIVE, 'nl' => ExportedArticle::STATUS_SKIPPED], ['nl']),
            ],
            ['The articles refer to tags that do not exist on this system (any more): 9.'],
            [],
            [],
            ['media' => 1, 'pages' => 1],
            str_repeat('a', 32),
        );
    }

    public function testWritesThePackageAndPrintsTheReport(): void
    {
        $target = $this->directory.'/out.sulu-transfer.zip';
        $tester = $this->tester();

        $status = $tester->execute(['uuid' => [PackageFixtures::ARTICLE_1], '--output' => $target]);

        $this->assertSame(0, $status);
        $this->assertFileExists($target);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Erste Schritte', $display);
        $this->assertStringContainsString(PackageFixtures::ARTICLE_1, $display);
        $this->assertStringContainsString('draft (not published)', $display);
        $this->assertStringContainsString('skipped', $display);
        $this->assertStringContainsString('Images', $display);
        $this->assertStringContainsString('files in the package', $display);
        $this->assertStringContainsString('Package size', $display);
        $this->assertStringContainsString('media 1, pages 1', $display);
        $this->assertStringContainsString('The articles refer to tags', $display);
        $this->assertStringContainsString('The package was written.', $display);

        // the file is a readable package with the article and the image
        $extracted = (new PackageReader(new PackageLimits(), new WorkDirectory($this->workDir)))->read($target);
        $this->assertSame([PackageFixtures::ARTICLE_1], array_keys($extracted->package->articles));
        $this->assertSame([12], array_keys($extracted->package->media));
    }

    public function testPassesTheOptionsOnAndReleasesTheTemporaryFiles(): void
    {
        $tester = $this->tester($this->exportResult(false));
        $this->exporter->expects($this->once())->method('release');

        $tester->execute([
            'uuid' => [PackageFixtures::ARTICLE_1, PackageFixtures::ARTICLE_2],
            '--locale' => ['de', 'en'],
            '--stage' => 'draft',
            '--no-media-files' => true,
            '--output' => $this->directory.'/out.zip',
        ]);

        $this->assertNotNull($this->request);
        $this->assertSame([PackageFixtures::ARTICLE_1, PackageFixtures::ARTICLE_2], $this->request->uuids);
        $this->assertSame(['de', 'en'], $this->request->locales);
        $this->assertSame('draft', $this->request->stage);
        $this->assertFalse($this->request->includeMediaFiles);
        $this->assertStringContainsString('only named, files not included', $tester->getDisplay());
    }

    public function testDefaultsAreAllLanguagesAndTheLiveVersionWithImages(): void
    {
        $tester = $this->tester();
        $previous = getcwd();
        chdir($this->directory);

        try {
            $tester->execute(['uuid' => [PackageFixtures::ARTICLE_1]]);
        } finally {
            chdir((string) $previous);
        }

        $this->assertNotNull($this->request);
        $this->assertSame([], $this->request->locales);
        $this->assertSame('live', $this->request->stage);
        $this->assertTrue($this->request->includeMediaFiles);
        $files = glob($this->directory.'/*-erste-schritte.sulu-transfer.zip');
        $this->assertIsArray($files);
        $this->assertCount(1, $files, 'file name from the date and the first title');
        $this->assertMatchesRegularExpression('~/\d{4}-\d{2}-\d{2}-erste-schritte\.sulu-transfer\.zip$~', $files[0]);
    }

    public function testExportErrorsAreShownWithoutAStackTrace(): void
    {
        $tester = $this->tester(null, new ContentTransferException('Article not found: abc.', 'export.article_not_found'));

        $status = $tester->execute(['uuid' => ['abc'], '--output' => $this->directory.'/out.zip']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Article not found: abc.', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->directory.'/out.zip');
    }

    public function testNeedsAtLeastOneArticle(): void
    {
        $tester = $this->tester();

        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }

    public function testUnknownStageIsRefused(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['uuid' => [PackageFixtures::ARTICLE_1], '--stage' => 'preview', '--output' => $this->directory.'/out.zip']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Unknown version "preview"', $tester->getDisplay());
    }
}
