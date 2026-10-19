<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Export;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Export\ArticleExporter;
use Manuxi\SuluContentTransferBundle\Export\ExportedArticle;
use Manuxi\SuluContentTransferBundle\Export\ExportRequest;
use Manuxi\SuluContentTransferBundle\Export\MediaExport;
use Manuxi\SuluContentTransferBundle\Export\MediaExporter;
use Manuxi\SuluContentTransferBundle\Export\ReferenceLookupBuilder;
use Manuxi\SuluContentTransferBundle\Format\MediaEntry;
use Manuxi\SuluContentTransferBundle\Format\MediaMeta;
use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use Manuxi\SuluContentTransferBundle\Instance\VersionInfo;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Reference\ReferenceScanner;
use Manuxi\SuluContentTransferBundle\Reference\SuluReferenceMerger;
use Manuxi\SuluContentTransferBundle\Reference\SuluReferenceSource;
use Manuxi\SuluContentTransferBundle\Reference\TemplateSchema;
use Manuxi\SuluContentTransferBundle\Reference\TemplateSchemaProvider;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Sulu\Article\Domain\Exception\ArticleNotFoundException;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ArticleExporterTest extends TemporaryDirectoryTestCase
{
    private const UUID = '0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8';
    private const SHA = 'c67b8cce5b5ee6c31919bf34e3fdd403d797bd4bef14578d52153f82ad9c862a';

    /** @var MockObject&ArticleRepositoryInterface */
    private $repository;
    /** @var MockObject&ContentManagerInterface */
    private $contentManager;
    /** @var MockObject&MediaExporter */
    private $mediaExporter;
    /** @var list<array{string, string}> locale and stage of every read */
    private array $reads = [];

    /**
     * The article the exporter finds: de has a live version, en only a draft, fr is also a draft only.
     *
     * @param list<array{string, string}> $dimensions locale and stage of the dimension contents
     */
    private function exporter(array $dimensions = [['de', 'draft'], ['de', 'live'], ['en', 'draft']]): ArticleExporter
    {
        $contents = [];
        foreach ($dimensions as [$locale, $stage]) {
            $content = $this->createMock(ArticleDimensionContentInterface::class);
            $content->method('getLocale')->willReturn($locale);
            $content->method('getStage')->willReturn($stage);
            $content->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
            $contents[] = $content;
        }
        // the unlocalized dimension every article has
        $unlocalized = $this->createMock(ArticleDimensionContentInterface::class);
        $unlocalized->method('getLocale')->willReturn(null);
        $unlocalized->method('getStage')->willReturn('draft');
        $unlocalized->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $contents[] = $unlocalized;

        $article = $this->createMock(ArticleInterface::class);
        $article->method('getDimensionContents')->willReturn(new \Doctrine\Common\Collections\ArrayCollection($contents));

        $this->repository = $this->createMock(ArticleRepositoryInterface::class);
        $this->repository->method('findOneBy')->willReturnCallback(
            static fn (array $filters): ?ArticleInterface => self::UUID === ($filters['uuid'] ?? null) ? $article : null,
        );
        $this->repository->method('getOneBy')->willReturnCallback(function (array $filters) use ($article): ArticleInterface {
            if ('fr' === $filters['locale']) {
                throw new ArticleNotFoundException($filters);
            }
            $this->reads[] = [$filters['locale'], $filters['stage']];

            return $article;
        });

        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->contentManager->method('resolve')->willReturnCallback(function ($article, array $attributes) {
            $resolved = $this->createMock(DimensionContentInterface::class);
            $resolved->method('getLocale')->willReturn($attributes['locale']);
            $resolved->method('getStage')->willReturn($attributes['stage']);

            return $resolved;
        });
        $this->contentManager->method('normalize')->willReturnCallback(static fn (DimensionContentInterface $content): array => [
            '_hash' => 'volatile',
            'template' => 'tutorial',
            'locale' => $content->getLocale(),
            'stage' => $content->getStage(),
            'title' => 'live' === $content->getStage() ? 'Title '.$content->getLocale().' (live)' : 'Title '.$content->getLocale().' (draft)',
            'changed' => 'de' === $content->getLocale() ? '2026-10-18T10:00:00+02:00' : '2026-10-18T12:00:00+02:00',
            'published' => '2026-10-17T09:00:00+02:00',
            'mainPage' => '22222222-2222-4222-8222-222222222222',
            'image' => ['id' => 207],
            'gallery' => ['ids' => [207, 208]],
        ]);

        $normalizer = $this->createMock(NormalizerInterface::class);
        $normalizer->method('normalize')->willReturnArgument(0);

        $schemas = $this->createMock(TemplateSchemaProvider::class);
        $schemas->method('getSchema')->willReturn(TemplateSchema::fromArray([
            'mainPage' => ['type' => 'single_page_selection'],
            'image' => ['type' => 'single_media_selection'],
            'gallery' => ['type' => 'media_selection'],
        ]));

        $suluReferences = $this->createMock(SuluReferenceSource::class);
        $suluReferences->method('collect')->willReturn([]);

        $this->mediaExporter = $this->createMock(MediaExporter::class);
        $this->mediaExporter->method('export')->willReturnCallback(static function (array $ids, string $directory, bool $keepFiles): MediaExport {
            $entries = [];
            $files = [];
            $rows = [];
            foreach ($ids as $id) {
                $meta = new MediaMeta('image-'.$id.'.jpg', 'image/jpeg', 3, self::SHA);
                $entries[$id] = new MediaEntry($id, $meta, 'image-'.$id.'.jpg');
                $rows[] = ['id' => $id, 'filename' => 'image-'.$id.'.jpg', 'size' => 3, 'sha256' => self::SHA];
                if ($keepFiles) {
                    file_put_contents($directory.'/media-'.$id, 'abc');
                    $files[$id] = $directory.'/media-'.$id;
                }
            }

            return new MediaExport($entries, $files, $rows, []);
        });

        return new ArticleExporter(
            $this->repository,
            $this->contentManager,
            $normalizer,
            $schemas,
            new ReferenceScanner(),
            $suluReferences,
            new SuluReferenceMerger(),
            new ReferenceLookupBuilder(new FakeLookupSource(pages: ['22222222-2222-4222-8222-222222222222' => ['uuid' => '22222222-2222-4222-8222-222222222222', 'webspace' => 'your_webspace', 'urls' => [], 'titles' => ['de' => 'Seite']]])),
            $this->mediaExporter,
            new InstanceIdentity('secret', 'source-system', 'source.example.org'),
            new VersionInfo('3.0.9', '1.0.0'),
            new WorkDirectory($this->workDir),
        );
    }

    public function testExportsTheLiveVersionAndFallsBackToTheDraftOfALanguageThatIsNotPublished(): void
    {
        $exporter = $this->exporter();

        $result = $exporter->export(new ExportRequest([self::UUID], [], ExportRequest::STAGE_LIVE));

        $this->assertSame([['de', 'live'], ['en', 'draft']], $this->reads);
        $article = $result->package->articles[self::UUID];
        $this->assertSame('live', $article->dimensions['de']->stage);
        $this->assertTrue($article->dimensions['de']->published);
        $this->assertSame('2026-10-17T09:00:00+02:00', $article->dimensions['de']->publishedAt?->format('c'));
        $this->assertSame('draft', $article->dimensions['en']->stage);
        $this->assertFalse($article->dimensions['en']->published);
        $this->assertNull($article->dimensions['en']->publishedAt);
        $this->assertSame('Title de (live)', $article->dimensions['de']->data['title']);
        $this->assertSame('2026-10-18T12:00:00+02:00', $article->changed->format('c'), 'the newest change of the exported languages');

        $this->assertSame([self::UUID => ['de' => ExportedArticle::STATUS_LIVE, 'en' => ExportedArticle::STATUS_DRAFT_NOT_PUBLISHED]], [$result->articles[0]->uuid => $result->articles[0]->locales]);
        $exporter->release($result);
    }

    public function testExportsTheDraftWhenAskedTo(): void
    {
        $exporter = $this->exporter();

        $result = $exporter->export(new ExportRequest([self::UUID], ['de'], ExportRequest::STAGE_DRAFT));

        $this->assertSame([['de', 'draft']], $this->reads);
        $this->assertSame('draft', $result->package->articles[self::UUID]->dimensions['de']->stage);
        $this->assertFalse($result->package->articles[self::UUID]->dimensions['de']->published);
        $this->assertSame(ExportedArticle::STATUS_DRAFT, $result->articles[0]->locales['de']);
        $exporter->release($result);
    }

    public function testLanguagesTheArticleDoesNotHaveAreSkippedAndNoted(): void
    {
        $exporter = $this->exporter();

        $result = $exporter->export(new ExportRequest([self::UUID], ['de', 'fr', 'nl']));

        $this->assertSame(['de'], array_keys($result->package->articles[self::UUID]->dimensions));
        $this->assertSame(['fr', 'nl'], $result->articles[0]->skipped);
        $this->assertSame(ExportedArticle::STATUS_SKIPPED, $result->articles[0]->locales['fr']);
        $this->assertSame(['de'], $result->package->manifest->options->locales, 'only languages that were exported');
        $exporter->release($result);
    }

    public function testFailsWhenNoneOfTheLanguagesExist(): void
    {
        $exporter = $this->exporter();

        try {
            $exporter->export(new ExportRequest([self::UUID], ['fr']));
            $this->fail('An exception was expected.');
        } catch (ContentTransferException $exception) {
            $this->assertSame('export.nothing_to_export', $exception->getReason());
        }
        $this->assertSame(0, self::countFiles($this->workDir), 'nothing is left in the work directory');
    }

    public function testFailsForUnknownArticlesBeforeReadingAnything(): void
    {
        $exporter = $this->exporter();

        try {
            $exporter->export(new ExportRequest([self::UUID, 'ffffffff-ffff-4fff-8fff-ffffffffffff']));
            $this->fail('An exception was expected.');
        } catch (ContentTransferException $exception) {
            $this->assertSame('export.article_not_found', $exception->getReason());
            $this->assertStringContainsString('ffffffff-ffff-4fff-8fff-ffffffffffff', $exception->getMessage());
        }
        $this->assertSame([], $this->reads);
    }

    public function testRemovesFieldsThatOnlyMakeSenseOnTheSourceSystem(): void
    {
        $exporter = $this->exporter();

        $result = $exporter->export(new ExportRequest([self::UUID], ['de']));

        $this->assertArrayNotHasKey('_hash', $result->package->articles[self::UUID]->dimensions['de']->data);
        $exporter->release($result);
    }

    public function testManifestDescribesTheExport(): void
    {
        $exporter = $this->exporter();

        $result = $exporter->export(new ExportRequest([strtoupper(self::UUID)], [], ExportRequest::STAGE_LIVE));

        $manifest = $result->package->manifest;
        $this->assertSame('source-system', $manifest->source->instanceId);
        $this->assertSame('source.example.org', $manifest->source->label);
        $this->assertSame('3.0.9', $manifest->source->suluVersion);
        $this->assertSame(['de', 'en'], $manifest->options->locales);
        $this->assertSame('live', $manifest->options->stage);
        $this->assertTrue($manifest->options->includeMediaFiles);
        $this->assertSame('tutorial', $manifest->items[0]->templateKey);
        $this->assertSame(['de' => 'Title de (live)', 'en' => 'Title en (draft)'], $manifest->items[0]->titles);
        $this->assertSame(['22222222-2222-4222-8222-222222222222'], array_column($manifest->references->pages, 'uuid'));
        $this->assertSame([207, 208], array_column($manifest->references->media, 'id'));
        $this->assertSame(['media' => 6, 'pages' => 2], $result->referenceCount, 'image, two gallery images and a page, in two languages');
        $exporter->release($result);
    }

    public function testImagesGoIntoThePackageWhenRequested(): void
    {
        $exporter = $this->exporter();

        $result = $exporter->export(new ExportRequest([self::UUID], ['de'], ExportRequest::STAGE_LIVE, true));

        $this->assertSame([207, 208], array_keys($result->package->media));
        $this->assertSame([207, 208], array_keys($result->mediaFiles));
        $this->assertFileExists($result->mediaFiles[207]);
        $exporter->release($result);
        $this->assertFileDoesNotExist($result->mediaFiles[207], 'release removes the copies');
    }

    public function testWithoutMediaFilesOnlyTheLookupNamesTheImages(): void
    {
        $exporter = $this->exporter();

        $result = $exporter->export(new ExportRequest([self::UUID], ['de'], ExportRequest::STAGE_LIVE, false));

        $this->assertSame([], $result->package->media);
        $this->assertSame([], $result->mediaFiles);
        $this->assertSame([207, 208], array_column($result->package->manifest->references->media, 'id'));
        $this->assertFalse($result->package->manifest->options->includeMediaFiles);
        $this->assertSame(6, $result->mediaBytes());
        $exporter->release($result);
    }

    public function testRequestNeedsAnArticleAndAKnownStage(): void
    {
        $this->expectException(ContentTransferException::class);
        new ExportRequest([]);
    }

    public function testRequestRejectsUnknownStages(): void
    {
        $this->expectException(ContentTransferException::class);
        new ExportRequest([self::UUID], [], 'preview');
    }
}
