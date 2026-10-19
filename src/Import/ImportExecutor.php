<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;
use Manuxi\SuluContentTransferBundle\Exception\PlanChangedException;
use Manuxi\SuluContentTransferBundle\Format\ArticleDimension;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Format\MediaEntry;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanCategory;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Import\Report\ArticleResult;
use Manuxi\SuluContentTransferBundle\Import\Report\ImportReport;
use Manuxi\SuluContentTransferBundle\Import\Report\MediaResult;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\PlanRewriteMap;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\ReferenceRewriter;
use Manuxi\SuluContentTransferBundle\Import\Target\ArticleTarget;
use Manuxi\SuluContentTransferBundle\Import\Target\ReferenceTarget;
use Manuxi\SuluContentTransferBundle\Import\Write\AdminLinks;
use Manuxi\SuluContentTransferBundle\Import\Write\ArticleWriter;
use Manuxi\SuluContentTransferBundle\Import\Write\ImportUserContext;
use Manuxi\SuluContentTransferBundle\Import\Write\MediaWriter;
use Manuxi\SuluContentTransferBundle\Import\Write\TaxonomyWriter;
use Manuxi\SuluContentTransferBundle\Import\Write\Transaction;
use Manuxi\SuluContentTransferBundle\Package\ExtractedPackage;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Repository\TransferMappings;
use Symfony\Component\Uid\Uuid;

/**
 * Step 2 of an import: writes what the plan promised, with the decisions of the person who imports.
 *
 *  1. The plan is worked out again against the current state of this system; if it differs from the plan that was shown,
 *     nothing is written (PlanChangedException).
 *  2. Tags and categories the articles need and this system lacks are created, then the images (new ones, new file
 *     versions, texts of existing ones if wanted). Each in its own transaction.
 *  3. Per article, in its own transaction: all references are rewritten, then the article is created (first language)
 *     or modified (every language) through Sulu's messages, and published if wanted. An error rolls back that article
 *     and is reported; the others go on.
 *  4. The mapping table is updated, the report built, the unpacked package removed.
 *
 * Only what the articles that are written actually use is created: tags, categories and images of skipped articles stay
 * out. Without "keepStatus" everything stays a draft.
 */
class ImportExecutor
{
    /** Fields of the admin normal form that belong to the system, not to the content. */
    private const SYSTEM_KEYS = [
        'id', 'uuid', 'changed', 'created', 'creator', 'changer', 'version', 'stage', 'availableLocales', 'contentLocales',
        'ghostLocale', 'locale', 'publishedState', 'workflowPlace', 'published', 'shadowLocales', '_hash',
    ];

    /** @var callable(): string */
    private $uuidGenerator;

    /** @var array<string, int> tag name => id on this system */
    private array $tagIds = [];

    /** @var list<array{id: int, name: string}> */
    private array $createdTags = [];

    /**
     * @param ?callable(): string $uuidGenerator makes the UUID of an article that is imported as a copy
     */
    public function __construct(
        private readonly PackageReader $reader,
        private readonly PlanStore $planStore,
        private readonly ImportAnalyzer $analyzer,
        private readonly ImportUserContext $user,
        private readonly TaxonomyWriter $taxonomy,
        private readonly MediaWriter $media,
        private readonly ArticleWriter $articles,
        private readonly Transaction $transaction,
        private readonly ArticleTarget $articleTarget,
        private readonly ReferenceTarget $referenceTarget,
        private readonly TransferMappings $mappings,
        private readonly ReferenceRewriter $rewriter,
        private readonly AdminLinks $adminLinks,
        ?callable $uuidGenerator = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Uuid::v7()->toRfc4122();
    }

    /**
     * @throws \Manuxi\SuluContentTransferBundle\Exception\ContentTransferException when nothing could be started (unknown
     *                                                                              user or token, expired or changed plan,
     *                                                                              decisions the plan does not allow)
     */
    public function execute(string $token, ImportDecisions $decisions, string $username): ImportReport
    {
        $startedAt = new \DateTimeImmutable();
        $this->tagIds = [];
        $this->createdTags = [];

        $this->user->activate($username);
        $extracted = $this->reader->open($token);
        $plan = $this->planStore->load($token);

        $current = $this->analyzer->plan($extracted, $startedAt);
        if (self::fingerprint($plan) !== self::fingerprint($current)) {
            throw new PlanChangedException();
        }
        $decisions->validate($plan);

        try {
            return $this->run($extracted, $plan, $decisions, $startedAt);
        } finally {
            $this->planStore->remove($token);
        }
    }

    /**
     * Throws away the unpacked package and the plan of a token (when the import is not carried out).
     */
    public function discard(string $token): void
    {
        $this->planStore->remove($token);
    }

    private function run(ExtractedPackage $extracted, ImportPlan $plan, ImportDecisions $decisions, \DateTimeImmutable $startedAt): ImportReport
    {
        $package = $extracted->package;
        $map = new PlanRewriteMap($plan);

        // which articles are written, in which languages, and under which UUID
        /** @var array<string, array{article: PlanArticle, action: string, locales: list<string>, target: string}> $jobs */
        $jobs = [];
        $results = [];
        foreach ($plan->articles as $article) {
            $action = $decisions->actionFor($article);
            $locales = $decisions->localesFor($article);
            if (ArticleAction::SKIP === $action || [] === $locales) {
                $map->setArticleTarget($article->uuid, $article->targetId);
                $results[$article->uuid] = new ArticleResult(
                    $article->uuid,
                    $article->title(),
                    $action,
                    ArticleResult::SKIPPED,
                    $article->targetId,
                    [],
                    self::skippedLocales($article),
                    [],
                    [],
                    [],
                    [],
                    ArticleAction::SKIP === $action ? 'Skipped.' : 'No language to import.',
                );

                continue;
            }

            $target = match ($action) {
                ArticleAction::CREATE => $article->uuid,
                ArticleAction::UPDATE => $article->targetId ?? $article->uuid,
                default => ($this->uuidGenerator)(),
            };
            $map->setArticleTarget($article->uuid, $target);
            $jobs[$article->uuid] = ['article' => $article, 'action' => $action, 'locales' => $locales, 'target' => $target];
        }

        // what the articles to write use
        $needed = $this->neededByJobs($plan, $package->articles, $jobs);
        $errors = [];
        $createdCategories = $this->createCategories($plan, $needed['categories'], $map, $errors);
        foreach ($needed['tags'] as $name) {
            $tagId = $this->tagId($name, $errors);
            if (null !== $tagId) {
                $map->addCreated('tag:'.$name, $tagId);
            }
        }
        $mediaResults = $this->importMedia($extracted, $plan, $decisions, array_keys($needed['media']), $map, $errors, $this->fallbackLocale($jobs));

        foreach ($jobs as $uuid => $job) {
            $results[$uuid] = $this->writeArticle($package->articles[$uuid], $job, $plan, $decisions, $map);
        }

        $articles = [];
        foreach ($plan->articles as $article) {
            $articles[] = $results[$article->uuid];
        }

        return new ImportReport($plan->token, $startedAt, new \DateTimeImmutable(), $articles, $mediaResults, $createdCategories, $this->createdTags, $errors);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // what is needed

    /**
     * @param array<string, ArticleEntry>                                                                           $entries
     * @param array<string, array{article: PlanArticle, action: string, locales: list<string>, target: string}>   $jobs
     *
     * @return array{media: array<int, true>, categories: array<string, true>, tags: list<string>}
     */
    private function neededByJobs(ImportPlan $plan, array $entries, array $jobs): array
    {
        $media = [];
        $categories = [];
        $tags = [];
        foreach ($jobs as $uuid => $job) {
            $locales = array_flip($job['locales']);
            foreach ($entries[$uuid]->references as $reference) {
                if (!isset($locales[$reference->locale])) {
                    continue;
                }
                $kind = 'tags' === $reference->resourceKey && \is_string($reference->id) ? 'tag_names' : $reference->resourceKey;
                $resolution = $plan->resolution($kind, $reference->id);
                if (null === $resolution) {
                    continue;
                }
                if ('media' === $kind) {
                    $media[(int) $reference->id] = true;
                } elseif (PlanResolution::PLANNED === $resolution->status && null !== $resolution->ref) {
                    if ('categories' === $kind) {
                        $categories[$resolution->ref] = true;
                    } elseif (\in_array($kind, ['tags', 'tag_names'], true) && str_starts_with($resolution->ref, 'tag:')) {
                        $tags[substr($resolution->ref, 4)] = true;
                    }
                }
            }
        }
        ksort($media);
        $tagNames = array_map('strval', array_keys($tags));
        sort($tagNames, \SORT_STRING);

        return ['media' => $media, 'categories' => $categories, 'tags' => $tagNames];
    }

    /**
     * @param array<string, array{article: PlanArticle, action: string, locales: list<string>, target: string}> $jobs
     */
    private function fallbackLocale(array $jobs): string
    {
        foreach ($jobs as $job) {
            foreach ($job['locales'] as $locale) {
                return $locale;
            }
        }

        return 'en';
    }

    // ---------------------------------------------------------------------------------------------------------------
    // tags and categories

    /**
     * @param list<string> $errors
     */
    private function tagId(string $name, array &$errors): ?int
    {
        if (isset($this->tagIds[$name])) {
            return $this->tagIds[$name];
        }
        $existing = $this->referenceTarget->tagByName($name);
        if (null !== $existing) {
            return $this->tagIds[$name] = $existing;
        }

        try {
            $id = $this->transaction->run(function () use ($name): int {
                $this->user->refresh();

                return $this->taxonomy->createTag($name);
            });
        } catch (\Throwable $exception) { // @phpstan-ignore-line a failing unit of work is reported, the others go on
            $errors[] = \sprintf('The tag "%s" could not be created: %s', $name, $exception->getMessage());

            return null;
        }
        $this->createdTags[] = ['id' => $id, 'name' => $name];

        return $this->tagIds[$name] = $id;
    }

    /**
     * @param array<string, true> $neededRefs
     * @param list<string>        $errors
     *
     * @return list<array{id: int, key: ?string, name: string, path: string}>
     */
    private function createCategories(ImportPlan $plan, array $neededRefs, PlanRewriteMap $map, array &$errors): array
    {
        /** @var array<string, PlanCategory> $byRef */
        $byRef = [];
        foreach ($plan->categories as $category) {
            $byRef[$category->ref] = $category;
        }

        // the categories that are needed together with the parents that are created as well
        $required = [];
        foreach (array_keys($neededRefs) as $ref) {
            for ($current = $ref; null !== $current && isset($byRef[$current]) && !isset($required[$current]); $current = $byRef[$current]->parentRef) {
                $required[$current] = true;
            }
        }

        $created = [];
        $ids = [];
        $paths = [];
        $remaining = array_keys($required);
        // parents first: repeat until nothing more can be created
        while ([] !== $remaining) {
            $progress = false;
            foreach ($remaining as $index => $ref) {
                $category = $byRef[$ref];
                if (null !== $category->parentRef && !isset($ids[$category->parentRef])) {
                    if (!\in_array($category->parentRef, $remaining, true)) {
                        // the parent could not be created
                        unset($remaining[$index]);
                        $errors[] = \sprintf('The category "%s" was not created because its parent could not be created.', $category->label());
                        $progress = true;
                    }

                    continue;
                }
                unset($remaining[$index]);
                $progress = true;

                $parentId = null !== $category->parentRef ? $ids[$category->parentRef] : $category->parentId;
                try {
                    $id = $this->transaction->run(function () use ($category, $parentId): int {
                        $this->user->refresh();

                        return $this->taxonomy->createCategory($category->key, $category->names, $parentId);
                    });
                } catch (\Throwable $exception) { // @phpstan-ignore-line a failing unit of work is reported, the others go on
                    $errors[] = \sprintf('The category "%s" could not be created: %s', $category->label(), $exception->getMessage());

                    continue;
                }
                $ids[$ref] = $id;
                $map->addCreated($ref, $id);
                $paths[$ref] = (null !== $category->parentRef ? ($paths[$category->parentRef] ?? '').' > ' : '').$category->label();
                $created[] = ['id' => $id, 'key' => $category->key, 'name' => $category->label(), 'path' => ltrim($paths[$ref], ' >')];
            }
            if (!$progress) {
                break;
            }
        }

        return $created;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // images

    /**
     * @param list<int>    $ids
     * @param list<string> $errors
     *
     * @return list<MediaResult>
     */
    private function importMedia(ExtractedPackage $extracted, ImportPlan $plan, ImportDecisions $decisions, array $ids, PlanRewriteMap $map, array &$errors, string $fallbackLocale): array
    {
        $byId = [];
        foreach ($plan->media as $medium) {
            $byId[$medium->sourceId] = $medium;
        }

        $results = [];
        foreach ($ids as $id) {
            $medium = $byId[$id] ?? null;
            if (null === $medium) {
                continue;
            }
            $entry = $extracted->package->media[$id] ?? null;

            if (PlanMedium::MISSING === $medium->action) {
                $results[] = new MediaResult($id, $medium->filename, MediaResult::MISSING);

                continue;
            }

            try {
                $results[] = $this->transaction->run(fn (): MediaResult => $this->importOneMedium($extracted, $plan, $decisions, $medium, $entry, $map, $errors, $fallbackLocale));
            } catch (\Throwable $exception) { // @phpstan-ignore-line a failing unit of work is reported, the others go on
                $results[] = new MediaResult($id, $medium->filename, MediaResult::FAILED, null, false, [], $exception->getMessage());
            }
        }

        return $results;
    }

    /**
     * @param list<string> $errors
     */
    private function importOneMedium(ExtractedPackage $extracted, ImportPlan $plan, ImportDecisions $decisions, PlanMedium $medium, ?MediaEntry $entry, PlanRewriteMap $map, array &$errors, string $fallbackLocale): MediaResult
    {
        $this->user->refresh();
        $id = $medium->sourceId;

        if (PlanMedium::REUSE === $medium->action) {
            $textsUpdated = false;
            if ($decisions->overwriteMediaMeta && null !== $entry && null !== $medium->targetId) {
                $this->media->updateTexts($medium->targetId, $entry->meta);
                $textsUpdated = true;
            }
            $this->rememberMedium($plan, $id, (int) $medium->targetId);

            return new MediaResult($id, $medium->filename, MediaResult::REUSED, $medium->targetId, $textsUpdated);
        }

        if (null === $entry || null === $medium->targetId && PlanMedium::NEW_VERSION === $medium->action) {
            return new MediaResult($id, $medium->filename, MediaResult::FAILED, null, false, [], 'The file of the image is not in the package.');
        }
        $file = $extracted->mediaFilePath($entry);

        if (PlanMedium::NEW_VERSION === $medium->action) {
            $targetId = (int) $medium->targetId;
            $written = $this->media->addVersion($targetId, $file, $entry->meta, $fallbackLocale);
            $textsUpdated = false;
            if ($decisions->overwriteMediaMeta) {
                $this->media->updateTexts($targetId, $entry->meta);
                $textsUpdated = true;
            }
            $this->rememberMedium($plan, $id, $targetId);

            return new MediaResult($id, $medium->filename, MediaResult::NEW_VERSION, $targetId, $textsUpdated, $written->warnings);
        }

        // create
        $collection = $medium->collection ?? ['type' => 'system', 'key' => \Manuxi\SuluContentTransferBundle\DependencyInjection\SuluContentTransferExtension::SYSTEM_COLLECTION_KEY];
        $collectionId = 'collection' === $collection['type'] && isset($collection['id'])
            ? (int) $collection['id']
            : $this->media->systemCollectionId((string) ($collection['key'] ?? \Manuxi\SuluContentTransferBundle\DependencyInjection\SuluContentTransferExtension::SYSTEM_COLLECTION_KEY));

        $tagIds = [];
        foreach ($entry->meta->tags as $name) {
            $tagId = $this->tagId($name, $errors);
            if (null !== $tagId) {
                $tagIds[] = $tagId;
            }
        }
        // categories of an image are matched by their key; those this system does not have are left out
        $categoryIds = [];
        foreach ($entry->meta->categories as $category) {
            $categoryId = null !== $category['key'] ? $this->referenceTarget->categoryByKey($category['key']) : null;
            if (null !== $categoryId) {
                $categoryIds[] = $categoryId;
            }
        }

        $written = $this->media->create($file, $entry->meta, $collectionId, $tagIds, $categoryIds, $fallbackLocale);
        $map->addCreated('media:'.$id, $written->id);
        $this->rememberMedium($plan, $id, $written->id);

        return new MediaResult($id, $medium->filename, MediaResult::CREATED, $written->id, false, $written->warnings);
    }

    /**
     * Writes the row of the mapping table for an image (not for a package from this very system).
     */
    private function rememberMedium(ImportPlan $plan, int $sourceId, int $targetId): void
    {
        $this->remember($plan, 'media', (string) $sourceId, (string) $targetId, null);
    }

    private function remember(ImportPlan $plan, string $resourceKey, string $sourceId, string $targetId, ?\DateTimeImmutable $sourceChanged): void
    {
        if ($plan->sameInstance) {
            return;
        }
        $instance = $plan->source->instanceId;
        $now = new \DateTimeImmutable();
        $mapping = $this->mappings->findMapping($instance, $resourceKey, $sourceId);
        if (null === $mapping) {
            $this->mappings->add(new TransferMapping($instance, $resourceKey, $sourceId, $targetId, $now, $sourceChanged));

            return;
        }
        $mapping->setTargetId($targetId)->setImportedAt($now)->setSourceChanged($sourceChanged ?? $mapping->getSourceChanged());
    }

    // ---------------------------------------------------------------------------------------------------------------
    // articles

    /**
     * @param array{article: PlanArticle, action: string, locales: list<string>, target: string} $job
     */
    private function writeArticle(ArticleEntry $entry, array $job, ImportPlan $plan, ImportDecisions $decisions, PlanRewriteMap $map): ArticleResult
    {
        $article = $job['article'];

        try {
            return $this->transaction->run(fn (): ArticleResult => $this->writeArticleLanguages($entry, $job, $plan, $decisions, $map));
        } catch (\Throwable $exception) { // @phpstan-ignore-line a failing unit of work is reported, the others go on
            return new ArticleResult(
                $article->uuid,
                $article->title(),
                $job['action'],
                ArticleResult::FAILED,
                null,
                [],
                self::skippedLocales($article),
                [],
                [],
                [],
                [],
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param array{article: PlanArticle, action: string, locales: list<string>, target: string} $job
     */
    private function writeArticleLanguages(ArticleEntry $entry, array $job, ImportPlan $plan, ImportDecisions $decisions, PlanRewriteMap $map): ArticleResult
    {
        $article = $job['article'];
        $action = $job['action'];
        $target = $job['target'];
        $isNew = ArticleAction::UPDATE !== $action;

        $written = [];
        $published = [];
        $renamed = [];
        $removed = [];
        $links = [];

        foreach ($job['locales'] as $locale) {
            $dimension = $entry->dimensions[$locale];
            $rewritten = $this->rewriter->rewrite($locale, $dimension->data, $entry->references, $map);
            array_push($removed, ...$rewritten->removed);

            [$data, $rename] = $this->prepare($rewritten->data, $dimension, $article, $action, $target, $locale);
            if (null !== $rename) {
                $renamed[$locale] = $rename;
            }

            $this->user->refresh();
            if ($isNew && [] === $written) {
                $this->articles->create($target, $data);
            } else {
                $this->articles->modify($target, $data);
            }
            $written[] = $locale;

            if ($decisions->keepStatus && $dimension->published && 'live' === $dimension->stage) {
                $this->user->refresh();
                $this->articles->publish($target, $locale);
                $published[] = $locale;
            }
        }

        // the rows of the mapping table are looked up now, after the last clear() detached everything
        $this->user->refresh();
        $this->rememberArticle($plan, $article, $action, $target, $entry);

        foreach ($written as $locale) {
            $link = $this->adminLinks->article($target, $locale, $article->templateKey);
            if (null !== $link) {
                $links[$locale] = $link;
            }
        }

        return new ArticleResult(
            $article->uuid,
            $article->title(),
            $action,
            match ($action) {
                ArticleAction::CREATE => ArticleResult::CREATED,
                ArticleAction::COPY => ArticleResult::COPIED,
                default => ArticleResult::UPDATED,
            },
            $target,
            $written,
            self::skippedLocales($article),
            $published,
            $renamed,
            $removed,
            $links,
        );
    }

    private function rememberArticle(ImportPlan $plan, PlanArticle $article, string $action, string $target, ArticleEntry $entry): void
    {
        if (ArticleAction::COPY === $action && (null !== $article->targetId || $plan->sameInstance)) {
            // an existing article stays the partner of the source; a copy does not take its place
            return;
        }
        $this->remember($plan, 'articles', $article->uuid, $target, $entry->changed);
    }

    /**
     * Turns the rewritten normal form into what Sulu is told to save.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array{0: array<string, mixed>, 1: ?array{from: string, to: string}}
     */
    private function prepare(array $data, ArticleDimension $dimension, PlanArticle $article, string $action, string $target, string $locale): array
    {
        foreach (self::SYSTEM_KEYS as $key) {
            unset($data[$key]);
        }
        $data['locale'] = $locale;
        $template = $dimension->data['template'] ?? $article->templateKey;
        if (\is_string($template) && '' !== $template) {
            $data['template'] = $template;
        }
        if (null !== $article->targetWebspace) {
            $data['mainWebspace'] = $article->targetWebspace;
        }

        $rename = null;
        $url = ArticleAction::COPY === $action ? ($article->copyUrls[$locale] ?? null) : ($article->urls[$locale] ?? null);
        if (null !== $url && null !== $url->requested) {
            // other articles of this run may have taken the address in the meantime: ask the database again
            $exclude = ArticleAction::COPY === $action ? null : $target;
            $final = UniqueSlug::propose($url->requested, fn (string $slug): bool => null !== $this->articleTarget->routeOwner($locale, $slug, $exclude));
            if ($final !== $url->requested) {
                $rename = ['from' => $url->requested, 'to' => $final];
            }

            $page = null !== $url->parentPage ? $this->referenceTarget->page($url->parentPage) : null;
            $base = null !== $page ? rtrim($page->urls[$locale] ?? '', '/') : null;
            if (null !== $page && null !== $base && str_starts_with($final, $base)) {
                $data['url'] = ['page' => ['uuid' => $page->uuid, 'path' => $base], 'suffix' => substr($final, \strlen($base))];
            } else {
                $data['url'] = $final;
            }
        }

        /** @var array<string, mixed> $data */

        return [$data, $rename];
    }

    /**
     * @return array<string, string>
     */
    private static function skippedLocales(PlanArticle $article): array
    {
        $skipped = [];
        foreach ($article->locales as $locale) {
            if (!$locale->import) {
                $skipped[$locale->locale] = $locale->reason ?? 'skipped';
            }
        }

        return $skipped;
    }

    /**
     * The plan without what differs between two runs of the same analysis.
     */
    private static function fingerprint(ImportPlan $plan): string
    {
        $data = $plan->toArray();
        unset($data['token'], $data['createdAt'], $data['expiresAt'], $data['targetLabel']);

        // Json::encode sorts the keys: the stored plan has been through a JSON file, the new one has not
        return Json::encode($data);
    }
}
