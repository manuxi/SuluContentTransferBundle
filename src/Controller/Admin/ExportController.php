<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Controller\Admin;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Exception\ExportDeniedException;
use Manuxi\SuluContentTransferBundle\Export\ArticleExporter;
use Manuxi\SuluContentTransferBundle\Export\ExportedArticle;
use Manuxi\SuluContentTransferBundle\Export\ExportFileName;
use Manuxi\SuluContentTransferBundle\Export\ExportRequest;
use Manuxi\SuluContentTransferBundle\Export\ExportResult;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Security\ExportAccess;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /admin/api/content-transfer/export/preview   body: {"ids": [...], "locales": [...], "stage": "live"}   query: locale
 * POST /admin/api/content-transfer/export           body: {"ids": [...], "locales": [...], "stage": "live", "includeMediaFiles": true}
 *
 * The preview tells what an export of the selection would contain: the languages the articles have (and the user may
 * see), the images with their size, and the articles the selection links to but does not contain. The export answers
 * with the package file as download. Both use the exporter of the console command, so a package from the admin is the
 * same as one from the console.
 *
 * The user needs the permission to view every selected article, checked per article and language in the security
 * context of its group; one article that is denied denies the request. Errors answer as {"error": text, "code": reason}
 * with the reasons of the ContentTransferException, so the admin can show them in the language of the user.
 *
 * Own prefix on purpose: a path below /admin/api/{resourceKey}/ would collide with the routes of the resources.
 */
class ExportController
{
    private const MAX_ARTICLES = 500;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const LOCALE_PATTERN = '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/';

    public function __construct(
        private readonly ArticleExporter $exporter,
        private readonly PackageWriter $writer,
        private readonly WorkDirectory $workDirectory,
        private readonly ExportAccess $access,
    ) {
    }

    #[Route('/content-transfer/export/preview', name: 'export_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        try {
            [$uuids, $locales, $stage] = $this->parse($request);
            $scope = $this->access->resolve($uuids, $locales);
            // the files themselves are not needed to count the images and their size
            $result = $this->exporter->export(new ExportRequest($uuids, $scope->locales, $stage, false));
        } catch (ContentTransferException $exception) {
            return $this->error($exception);
        }

        try {
            return new JsonResponse($this->describe($result, $scope->available, $request->query->getString('locale')));
        } finally {
            $this->exporter->release($result);
        }
    }

    #[Route('/content-transfer/export', name: 'export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        try {
            [$uuids, $locales, $stage] = $this->parse($request);
            $scope = $this->access->resolve($uuids, $locales);
            $result = $this->exporter->export(new ExportRequest($uuids, $scope->locales, $stage, $this->includeMediaFiles($request)));
        } catch (ContentTransferException $exception) {
            return $this->error($exception);
        }

        // The file is written completely before the answer starts: a failure then still gives a proper error message.
        $token = $this->workDirectory->create();
        $file = $this->workDirectory->path($token).\DIRECTORY_SEPARATOR.'package.zip';
        try {
            $this->writer->writeToFile($result->package, $file, $result->mediaFiles);
            $size = filesize($file);
        } catch (ContentTransferException $exception) {
            $this->workDirectory->remove($token);

            return $this->error($exception);
        } finally {
            $this->exporter->release($result);
        }

        $response = new StreamedResponse(function () use ($file, $token): void {
            try {
                $source = fopen($file, 'rb');
                $target = fopen('php://output', 'wb');
                if (false !== $source && false !== $target) {
                    stream_copy_to_stream($source, $target);
                }
            } finally {
                $this->workDirectory->remove($token);
            }
        });

        $name = ExportFileName::for($result->articles);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $name));
        $response->headers->set('Cache-Control', 'no-store');
        if (false !== $size) {
            $response->headers->set('Content-Length', (string) $size);
        }

        return $response;
    }

    /**
     * @param list<string> $available
     *
     * @return array<string, mixed>
     */
    private function describe(ExportResult $result, array $available, string $locale): array
    {
        $articles = [];
        $unpublished = 0;
        foreach ($result->articles as $article) {
            $locales = array_filter($article->locales, static fn (string $status): bool => ExportedArticle::STATUS_SKIPPED !== $status);
            $unpublished += \count(array_filter($locales, static fn (string $status): bool => ExportedArticle::STATUS_DRAFT_NOT_PUBLISHED === $status));
            $articles[] = ['id' => $article->uuid, 'title' => $article->title, 'locales' => $locales];
        }

        $linkedRows = [];
        foreach ($result->package->manifest->references->articles as $row) {
            $linkedRows[strtolower((string) $row['uuid'])] = $row;
        }
        $linked = [];
        foreach ($this->access->filterViewable(array_keys($linkedRows)) as $uuid) {
            $titles = \is_array($linkedRows[$uuid]['titles'] ?? null) ? array_filter($linkedRows[$uuid]['titles'], 'is_string') : [];
            $title = $titles[$locale] ?? (reset($titles) ?: $uuid);
            $linked[] = ['id' => $uuid, 'title' => (string) $title];
        }

        return [
            'articles' => $articles,
            'locales' => $available,
            'unpublished' => $unpublished,
            'media' => ['count' => $result->mediaCount(), 'bytes' => $result->mediaBytes()],
            'linkedArticles' => $linked,
            'warnings' => $result->warnings,
        ];
    }

    /**
     * @return array{list<string>, list<string>, string}
     */
    private function parse(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new ContentTransferException('The request must be a JSON object.', 'export.bad_request');
        }

        $uuids = $this->strings($data['ids'] ?? [], self::UUID_PATTERN, 'ids');
        if ([] === $uuids) {
            throw new ContentTransferException('No article was selected for the export.', 'export.nothing_selected');
        }
        if (\count($uuids) > self::MAX_ARTICLES) {
            throw new ContentTransferException(\sprintf('At most %d articles can be exported at once.', self::MAX_ARTICLES), 'export.too_many');
        }

        $stage = $data['stage'] ?? ExportRequest::STAGE_LIVE;
        if (!\is_string($stage)) {
            throw new ContentTransferException('The version must be "live" or "draft".', 'export.unknown_stage');
        }

        return [$uuids, $this->strings($data['locales'] ?? [], self::LOCALE_PATTERN, 'locales'), $stage];
    }

    private function includeMediaFiles(Request $request): bool
    {
        $data = json_decode($request->getContent(), true);

        return !\is_array($data) || false !== ($data['includeMediaFiles'] ?? true);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value, string $pattern, string $field): array
    {
        if (!\is_array($value)) {
            throw new ContentTransferException(\sprintf('"%s" must be a list.', $field), 'export.bad_request');
        }

        $strings = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || 1 !== preg_match($pattern, $entry)) {
                throw new ContentTransferException(\sprintf('"%s" contains an invalid entry.', $field), 'export.bad_request');
            }
            $strings[] = $entry;
        }

        return array_values(array_unique($strings));
    }

    private function error(ContentTransferException $exception): JsonResponse
    {
        $status = match ($exception->getReason()) {
            ExportDeniedException::REASON => Response::HTTP_FORBIDDEN,
            'export.article_not_found' => Response::HTTP_NOT_FOUND,
            'export.bad_request', 'export.nothing_selected', 'export.too_many', 'export.unknown_stage' => Response::HTTP_BAD_REQUEST,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };

        return new JsonResponse(['error' => $exception->getMessage(), 'code' => $exception->getReason()], $status);
    }
}
