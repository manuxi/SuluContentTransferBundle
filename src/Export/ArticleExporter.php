<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Format\ArticleDimension;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use Manuxi\SuluContentTransferBundle\Format\Manifest;
use Manuxi\SuluContentTransferBundle\Format\ManifestItem;
use Manuxi\SuluContentTransferBundle\Format\ManifestOptions;
use Manuxi\SuluContentTransferBundle\Format\ManifestSource;
use Manuxi\SuluContentTransferBundle\Format\Package;
use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use Manuxi\SuluContentTransferBundle\Instance\VersionInfo;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Reference\ReferenceScanner;
use Manuxi\SuluContentTransferBundle\Reference\SuluReferenceMerger;
use Manuxi\SuluContentTransferBundle\Reference\SuluReferenceSource;
use Manuxi\SuluContentTransferBundle\Reference\TemplateSchemaProvider;
use Sulu\Article\Domain\Exception\ArticleNotFoundException;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Reads articles from this system and puts them, with the references and the images they need, into a package.
 *
 * The content of every language is the admin form of the article: what GET /admin/api/articles/<id> returns and what
 * the admin sends back when it saves (and what Sulu's ModifyArticleMessage takes).
 */
class ArticleExporter
{
    /**
     * Fields of the admin form that only make sense on the system they were read from.
     */
    private const VOLATILE_FIELDS = ['_hash'];

    public function __construct(
        private readonly ArticleRepositoryInterface $articleRepository,
        private readonly ContentManagerInterface $contentManager,
        private readonly NormalizerInterface $normalizer,
        private readonly TemplateSchemaProvider $schemaProvider,
        private readonly ReferenceScanner $scanner,
        private readonly SuluReferenceSource $suluReferences,
        private readonly SuluReferenceMerger $merger,
        private readonly ReferenceLookupBuilder $lookupBuilder,
        private readonly MediaExporter $mediaExporter,
        private readonly InstanceIdentity $identity,
        private readonly VersionInfo $versions,
        private readonly WorkDirectory $workDirectory,
    ) {
    }

    /**
     * @throws ContentTransferException when an article does not exist or nothing could be exported
     */
    public function export(ExportRequest $request): ExportResult
    {
        $uuids = array_values(array_unique(array_map('strtolower', $request->uuids)));

        /** @var array<string, ArticleInterface> $articles */
        $articles = [];
        $unknown = [];
        foreach ($uuids as $uuid) {
            $article = $this->articleRepository->findOneBy(['uuid' => $uuid]);
            if (null === $article) {
                $unknown[] = $uuid;

                continue;
            }
            $articles[$uuid] = $article;
        }

        if ([] !== $unknown) {
            throw new ContentTransferException(\sprintf('Article not found: %s.', implode(', ', $unknown)), 'export.article_not_found');
        }

        $entries = [];
        $items = [];
        $report = [];
        $allReferences = [];
        $exportedLocales = [];

        foreach ($articles as $uuid => $article) {
            $available = self::availableLocales($article);
            $wanted = [] === $request->locales ? $available : array_values(array_unique($request->locales));

            $dimensions = [];
            $references = [];
            $status = [];
            $skipped = [];

            foreach ($wanted as $locale) {
                if (!\in_array($locale, $available, true)) {
                    $skipped[] = $locale;
                    $status[$locale] = ExportedArticle::STATUS_SKIPPED;

                    continue;
                }

                $useLive = ExportRequest::STAGE_LIVE === $request->stage && self::hasLive($article, $locale);
                $stage = $useLive ? DimensionContentInterface::STAGE_LIVE : DimensionContentInterface::STAGE_DRAFT;

                [$dimensionContent, $data] = $this->read($uuid, $locale, $stage);
                $templateKey = \is_string($data['template'] ?? null) ? $data['template'] : '';
                $schema = $this->schemaProvider->getSchema($templateKey, $locale);

                $scanned = $this->scanner->scan($locale, $data, $schema);
                $merged = $this->merger->merge($locale, $data, $scanned, $this->suluReferences->collect($dimensionContent));
                $references = [...$references, ...$merged['references']];

                $publishedAt = self::dateOf($data['published'] ?? null);
                $dimensions[] = new ArticleDimension($locale, $stage, $useLive, $useLive ? $publishedAt : null, $data);
                $exportedLocales[$locale] = true;

                $status[$locale] = $useLive
                    ? ExportedArticle::STATUS_LIVE
                    : (ExportRequest::STAGE_LIVE === $request->stage ? ExportedArticle::STATUS_DRAFT_NOT_PUBLISHED : ExportedArticle::STATUS_DRAFT);
            }

            $title = '';
            $titles = [];
            $templateKey = null;
            $changed = null;
            foreach ($dimensions as $dimension) {
                $titles[$dimension->locale] = \is_string($dimension->data['title'] ?? null) ? $dimension->data['title'] : '';
                $title = '' === $title ? $titles[$dimension->locale] : $title;
                $templateKey ??= \is_string($dimension->data['template'] ?? null) ? $dimension->data['template'] : null;
                $dimensionChanged = self::dateOf($dimension->data['changed'] ?? null);
                if (null !== $dimensionChanged && (null === $changed || $dimensionChanged > $changed)) {
                    $changed = $dimensionChanged;
                }
            }

            $report[] = new ExportedArticle($uuid, $title, $status, $skipped);
            if ([] === $dimensions) {
                continue;
            }

            $entries[] = new ArticleEntry($uuid, $changed ?? new \DateTimeImmutable(), $dimensions, $references);
            $items[] = new ManifestItem(ManifestItem::RESOURCE_ARTICLES, $uuid, $templateKey, $titles);
            $allReferences = [...$allReferences, ...$references];
        }

        if ([] === $entries) {
            throw new ContentTransferException('None of the requested languages exist in the selected articles, nothing was exported.', 'export.nothing_to_export');
        }

        $token = $this->workDirectory->create();

        try {
            return $this->finish($request, $entries, $items, $report, $allReferences, array_keys($exportedLocales), $token);
        } catch (\Throwable $exception) {
            $this->workDirectory->remove($token);

            throw $exception;
        }
    }

    /**
     * Deletes the temporary copies of the image files. Call it when the package has been written.
     */
    public function release(ExportResult $result): void
    {
        $this->workDirectory->remove($result->workToken);
    }

    /**
     * @param list<ArticleEntry>     $entries
     * @param list<ManifestItem>     $items
     * @param list<ExportedArticle>  $report
     * @param list<ArticleReference> $references
     * @param list<string>           $locales
     */
    private function finish(ExportRequest $request, array $entries, array $items, array $report, array $references, array $locales, string $token): ExportResult
    {
        $mediaIds = [];
        $referenceCount = [];
        foreach ($references as $reference) {
            $referenceCount[$reference->resourceKey] = ($referenceCount[$reference->resourceKey] ?? 0) + 1;
            if (ReferenceScanner::MEDIA === $reference->resourceKey && (\is_int($reference->id) || ctype_digit((string) $reference->id))) {
                $mediaIds[] = (int) $reference->id;
            }
        }
        ksort($referenceCount);

        $media = $this->mediaExporter->export($mediaIds, $this->workDirectory->path($token), $request->includeMediaFiles);

        $uuids = array_map(static fn (ArticleEntry $entry): string => $entry->uuid, $entries);
        $lookup = $this->lookupBuilder->build($references, $uuids, $media->rows);

        $missing = $lookup->missing;
        if ([] !== $media->missing) {
            $missing[ReferenceScanner::MEDIA] = $media->missing;
        }

        $warnings = [];
        foreach ($missing as $kind => $ids) {
            $warnings[] = \sprintf('The articles refer to %s that do not exist on this system (any more): %s.', $kind, implode(', ', $ids));
        }
        foreach ($lookup->withoutLookup as $kind => $ids) {
            $warnings[] = \sprintf('The articles refer to %s (ids %s). The references are kept, but the package cannot describe them, so another system cannot find them.', $kind, implode(', ', $ids));
        }

        $manifest = new Manifest(
            new \DateTimeImmutable(),
            new ManifestSource(
                $this->identity->getId(),
                $this->identity->getLabel(),
                $this->versions->getSuluVersion(),
                $this->versions->getBundleVersion(),
                null,
            ),
            new ManifestOptions($locales, $request->stage, $request->includeMediaFiles),
            $items,
            $lookup->lookup,
        );

        $package = new Package($manifest, $entries, $request->includeMediaFiles ? array_values($media->entries) : []);

        return new ExportResult($package, $media->files, $report, $warnings, $missing, $lookup->withoutLookup, $referenceCount, $token);
    }

    /**
     * @return array{0: DimensionContentInterface<ArticleInterface>, 1: array<string, mixed>}
     */
    private function read(string $uuid, string $locale, string $stage): array
    {
        $attributes = ['locale' => $locale, 'stage' => $stage];

        try {
            $article = $this->articleRepository->getOneBy(
                [...['uuid' => $uuid, 'loadGhost' => false], ...$attributes],
                [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true],
            );
        } catch (ArticleNotFoundException $exception) {
            throw new ContentTransferException(\sprintf('Article %s has no %s version in "%s".', $uuid, $stage, $locale), 'export.version_not_found', $exception);
        }

        $dimensionContent = $this->contentManager->resolve($article, $attributes);
        $normalized = $this->contentManager->normalize($dimensionContent);

        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize(
            $normalized,
            'json',
            ['sulu_admin' => true, 'sulu_admin_article' => true, 'sulu_admin_article_content' => true],
        );

        foreach (self::VOLATILE_FIELDS as $field) {
            unset($data[$field]);
        }

        return [$dimensionContent, $data];
    }

    /**
     * The languages an article has content in (a draft with a language).
     *
     * @return list<string>
     */
    private static function availableLocales(ArticleInterface $article): array
    {
        $locales = [];
        foreach ($article->getDimensionContents() as $dimensionContent) {
            if (self::isCurrent($dimensionContent, DimensionContentInterface::STAGE_DRAFT) && null !== $dimensionContent->getLocale()) {
                $locales[$dimensionContent->getLocale()] = true;
            }
        }
        $locales = array_keys($locales);
        sort($locales, \SORT_STRING);

        return $locales;
    }

    private static function hasLive(ArticleInterface $article, string $locale): bool
    {
        foreach ($article->getDimensionContents() as $dimensionContent) {
            if (self::isCurrent($dimensionContent, DimensionContentInterface::STAGE_LIVE) && $dimensionContent->getLocale() === $locale) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param DimensionContentInterface<ArticleInterface> $dimensionContent
     */
    private static function isCurrent(DimensionContentInterface $dimensionContent, string $stage): bool
    {
        return $stage === $dimensionContent->getStage() && DimensionContentInterface::CURRENT_VERSION === $dimensionContent->getVersion();
    }

    private static function dateOf(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        // the admin form writes dates as ISO 8601 with a time zone
        $date = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);

        return false === $date ? null : $date;
    }
}
