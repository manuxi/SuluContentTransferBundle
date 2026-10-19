<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Controller;

use Manuxi\SuluContentTransferBundle\Controller\Admin\ExportController;
use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Exception\ExportDeniedException;
use Manuxi\SuluContentTransferBundle\Export\ArticleExporter;
use Manuxi\SuluContentTransferBundle\Export\ExportedArticle;
use Manuxi\SuluContentTransferBundle\Export\ExportRequest;
use Manuxi\SuluContentTransferBundle\Export\ExportResult;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Security\ExportAccess;
use Manuxi\SuluContentTransferBundle\Security\ExportScope;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportControllerTest extends TemporaryDirectoryTestCase
{
    private const ARTICLE_1 = PackageFixtures::ARTICLE_1;
    private const ARTICLE_2 = PackageFixtures::ARTICLE_2;

    /** @var MockObject&ArticleExporter */
    private $exporter;
    /** @var MockObject&ExportAccess */
    private $access;
    private ?ExportRequest $exportRequest = null;
    private int $released = 0;

    private function controller(?\Throwable $accessFailure = null, ?\Throwable $exportFailure = null, bool $withMedia = true): ExportController
    {
        $this->exporter = $this->createMock(ArticleExporter::class);
        $this->exporter->method('export')->willReturnCallback(function (ExportRequest $request) use ($exportFailure, $withMedia): ExportResult {
            $this->exportRequest = $request;
            if (null !== $exportFailure) {
                throw $exportFailure;
            }

            return $this->result($withMedia && $request->includeMediaFiles);
        });
        $this->exporter->method('release')->willReturnCallback(function (): void {
            ++$this->released;
        });

        $this->access = $this->createMock(ExportAccess::class);
        $this->access->method('resolve')->willReturnCallback(static function (array $uuids, array $locales) use ($accessFailure): ExportScope {
            if (null !== $accessFailure) {
                throw $accessFailure;
            }

            return new ExportScope(['de', 'en'], [] === $locales ? ['de', 'en'] : $locales);
        });
        $this->access->method('filterViewable')->willReturnCallback(static fn (array $uuids): array => $uuids);

        $workDirectory = new WorkDirectory($this->workDir);

        return new ExportController($this->exporter, new PackageWriter(new PackageLimits(), $workDirectory), $workDirectory, $this->access);
    }

    private function result(bool $withMedia): ExportResult
    {
        $fixture = PackageFixtures::package($this->directory, $withMedia);

        return new ExportResult(
            $fixture['package'],
            $fixture['mediaFiles'],
            [
                new ExportedArticle(self::ARTICLE_1, 'Erste Schritte', ['de' => ExportedArticle::STATUS_DRAFT_NOT_PUBLISHED, 'en' => ExportedArticle::STATUS_LIVE, 'nl' => ExportedArticle::STATUS_SKIPPED], ['nl']),
            ],
            ['The articles refer to tags that do not exist on this system (any more): 9.'],
            [],
            [],
            ['media' => 1],
            str_repeat('a', 32),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body, string $query = ''): Request
    {
        return Request::create('/admin/api/content-transfer/export'.$query, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testPreviewDescribesTheExport(): void
    {
        $response = $this->controller()->preview($this->request(['ids' => [self::ARTICLE_1]], '?locale=de'));

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame([['id' => self::ARTICLE_1, 'title' => 'Erste Schritte', 'locales' => ['de' => 'draft (not published)', 'en' => 'live']]], $data['articles']);
        $this->assertSame(['de', 'en'], $data['locales']);
        $this->assertSame(1, $data['unpublished']);
        $this->assertSame(['count' => 1, 'bytes' => \strlen("not really a jpeg \x00\x01\xff binary")], $data['media']);
        $this->assertSame([['id' => self::ARTICLE_2, 'title' => 'Nicht exportiert']], $data['linkedArticles']);
        $this->assertSame(['The articles refer to tags that do not exist on this system (any more): 9.'], $data['warnings']);
    }

    public function testPreviewDoesNotNeedTheImageFilesAndReleasesTemporaryFiles(): void
    {
        $this->controller()->preview($this->request(['ids' => [self::ARTICLE_1], 'locales' => ['en'], 'stage' => 'draft']));

        $this->assertNotNull($this->exportRequest);
        $this->assertFalse($this->exportRequest->includeMediaFiles);
        $this->assertSame(['en'], $this->exportRequest->locales);
        $this->assertSame('draft', $this->exportRequest->stage);
        $this->assertSame(1, $this->released);
    }

    public function testPreviewOnlyNamesLinkedArticlesTheUserMayView(): void
    {
        $controller = $this->controller();
        $this->access = $this->createMock(ExportAccess::class);
        $this->access->method('resolve')->willReturn(new ExportScope(['de'], ['de']));
        $this->access->method('filterViewable')->willReturn([]);
        $workDirectory = new WorkDirectory($this->workDir);
        $controller = new ExportController($this->exporter, new PackageWriter(new PackageLimits(), $workDirectory), $workDirectory, $this->access);

        $data = $this->json($controller->preview($this->request(['ids' => [self::ARTICLE_1]])));

        $this->assertSame([], $data['linkedArticles']);
    }

    public function testExportAnswersWithTheZipAsDownload(): void
    {
        $response = $this->controller()->export($this->request(['ids' => [self::ARTICLE_1], 'locales' => ['de', 'en']]));

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename=\d{4}-\d{2}-\d{2}-erste-schritte\.sulu-transfer\.zip$/', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertSame((string) \strlen($content), $response->headers->get('Content-Length'));
        $file = $this->directory.'/download.zip';
        file_put_contents($file, $content);
        $package = (new PackageReader(new PackageLimits(), new WorkDirectory($this->workDir)))->read($file);
        $this->assertSame([self::ARTICLE_1], array_keys($package->package->articles));
        $this->assertSame([12], array_keys($package->package->media));

        $this->assertSame(['de', 'en'], $this->exportRequest?->locales);
        $this->assertTrue($this->exportRequest->includeMediaFiles);
        $this->assertSame('live', $this->exportRequest->stage);
        $this->assertSame(1, $this->released);
    }

    public function testExportWithoutImageFiles(): void
    {
        $response = $this->controller()->export($this->request(['ids' => [self::ARTICLE_1], 'includeMediaFiles' => false]));

        $this->assertNotNull($this->exportRequest);
        $this->assertFalse($this->exportRequest->includeMediaFiles);

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();
        $file = $this->directory.'/download.zip';
        file_put_contents($file, $content);
        $package = (new PackageReader(new PackageLimits(), new WorkDirectory($this->workDir)))->read($file);
        $this->assertSame([], $package->package->media);
    }

    public function testTemporaryFilesAreGoneAfterTheDownload(): void
    {
        $response = $this->controller()->export($this->request(['ids' => [self::ARTICLE_1]]));

        ob_start();
        $response->sendContent();
        ob_end_clean();

        $this->assertSame(0, self::countFiles($this->workDir));
    }

    public function testDeniedUserGetsForbiddenForPreviewAndExport(): void
    {
        $controller = $this->controller(new ExportDeniedException([self::ARTICLE_1]));

        foreach ([$controller->preview($this->request(['ids' => [self::ARTICLE_1]])), $controller->export($this->request(['ids' => [self::ARTICLE_1]]))] as $response) {
            $this->assertInstanceOf(JsonResponse::class, $response);
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('export.denied', $this->json($response)['code']);
            $this->assertStringContainsString(self::ARTICLE_1, $this->json($response)['error']);
        }

        // nothing was read from the articles
        $this->exporter->expects($this->never())->method('export');
    }

    public function testAnEmptyListOfLanguagesIsNeverPassedOnBecauseTheExporterReadsItAsAll(): void
    {
        $controller = $this->controller();
        $this->access = $this->createMock(ExportAccess::class);
        $this->access->method('resolve')->willReturn(new ExportScope([], []));
        $workDirectory = new WorkDirectory($this->workDir);
        $controller = new ExportController($this->exporter, new PackageWriter(new PackageLimits(), $workDirectory), $workDirectory, $this->access);

        foreach ([$controller->preview($this->request(['ids' => [self::ARTICLE_1]])), $controller->export($this->request(['ids' => [self::ARTICLE_1]]))] as $response) {
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame('export.nothing_to_export', $this->json($response)['code']);
        }
        $this->assertNull($this->exportRequest);
    }

    public function testUnknownArticleIsNotFound(): void
    {
        $controller = $this->controller(null, new ContentTransferException('Article not found: x.', 'export.article_not_found'));

        $response = $controller->export($this->request(['ids' => [self::ARTICLE_1]]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('export.article_not_found', $this->json($response)['code']);
    }

    public function testExportThatFindsNothingIsAnError(): void
    {
        $controller = $this->controller(null, new ContentTransferException('None of the requested languages exist.', 'export.nothing_to_export'));

        $response = $controller->preview($this->request(['ids' => [self::ARTICLE_1], 'locales' => ['nl']]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('export.nothing_to_export', $this->json($response)['code']);
    }

    /**
     * @dataProvider badRequests
     *
     * @param array<string, mixed>|string $body
     */
    public function testBadRequestsAreRejectedBeforeAnythingIsRead(array|string $body, string $code): void
    {
        $controller = $this->controller();
        $request = \is_string($body)
            ? Request::create('/x', 'POST', [], [], [], [], $body)
            : $this->request($body);

        foreach ([$controller->preview($request), $controller->export($request)] as $response) {
            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame($code, $this->json($response)['code']);
        }

        $this->exporter->expects($this->never())->method('export');
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, string}>
     */
    public static function badRequests(): iterable
    {
        yield 'no json' => ['not json', 'export.bad_request'];
        yield 'no ids' => [[], 'export.nothing_selected'];
        yield 'empty ids' => [['ids' => []], 'export.nothing_selected'];
        yield 'ids not a list' => [['ids' => 'abc'], 'export.bad_request'];
        yield 'id not a uuid' => [['ids' => ['1; DROP TABLE']], 'export.bad_request'];
        yield 'id not a string' => [['ids' => [5]], 'export.bad_request'];
        yield 'locale with path' => [['ids' => [self::ARTICLE_1], 'locales' => ['../de']], 'export.bad_request'];
        yield 'stage not a string' => [['ids' => [self::ARTICLE_1], 'stage' => ['live']], 'export.unknown_stage'];
        yield 'too many' => [['ids' => array_map(static fn (int $i): string => sprintf('4b1c0b3a-0000-4000-8000-%012d', $i), range(1, 501))], 'export.too_many'];
    }

    public function testUnknownStageIsRejectedByTheRequest(): void
    {
        $controller = $this->controller(null, new ContentTransferException('Unknown version "x", expected "live" or "draft".', 'export.unknown_stage'));

        $response = $controller->export($this->request(['ids' => [self::ARTICLE_1], 'stage' => 'x']));

        $this->assertSame(400, $response->getStatusCode());
    }
}
