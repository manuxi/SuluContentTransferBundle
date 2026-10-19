<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanCategory;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanLocale;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanUrl;
use Manuxi\SuluContentTransferBundle\Import\Target\ArticleTarget;
use Manuxi\SuluContentTransferBundle\Security\ImportAccess;

/**
 * The plan as the admin shows it: the parts of the ImportPlan the person decides on, a summary of the images, the
 * categories and tags that would be created, and what the current user may do with each article.
 */
class PlanPresenter
{
    public function __construct(
        private readonly ImportAccess $access,
        private readonly ArticleTarget $articleTarget,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ImportPlan $plan): array
    {
        $access = $this->access->describe($plan);

        $articles = [];
        $locales = [];
        foreach ($plan->articles as $article) {
            foreach ($article->locales as $locale) {
                $locales[$locale->locale] = ($locales[$locale->locale] ?? false) || $locale->import;
            }
            $articles[] = $this->article($article, $access[$article->uuid]->toArray());
        }
        ksort($locales, \SORT_STRING);
        $localeList = [];
        foreach ($locales as $locale => $import) {
            $localeList[] = ['locale' => (string) $locale, 'import' => $import];
        }

        return [
            'token' => $plan->token,
            'expiresAt' => $plan->expiresAt->format(\DATE_ATOM),
            'package' => [
                'label' => $plan->source->label,
                'instanceId' => $plan->source->instanceId,
                'createdAt' => $plan->packageCreatedAt->format(\DATE_ATOM),
                'suluVersion' => $plan->source->suluVersion,
                'sameInstance' => $plan->sameInstance,
            ],
            'target' => ['label' => $plan->targetLabel],
            'articles' => $articles,
            'locales' => $localeList,
            'media' => $this->media($plan),
            'categories' => $this->categories($plan),
            'tags' => $plan->tags,
            'notices' => array_map(static fn ($notice): array => $notice->toArray(), $plan->notices),
        ];
    }

    /**
     * @param array<string, mixed> $access
     *
     * @return array<string, mixed>
     */
    private function article(PlanArticle $article, array $access): array
    {
        return [
            'id' => $article->uuid,
            'title' => $article->title(),
            'titles' => $article->titles,
            'templateKey' => $article->templateKey,
            'state' => $article->state,
            'matchedBy' => $article->matchedBy,
            'targetId' => $article->targetId,
            'defaultAction' => $article->defaultAction,
            'allowedActions' => $article->allowedActions,
            'locales' => array_values(array_map(static fn (PlanLocale $locale): array => $locale->toArray(), $article->locales)),
            'urls' => array_map(static fn (PlanUrl $url): array => $url->toArray(), $article->urls),
            'copyUrls' => array_map(static fn (PlanUrl $url): array => $url->toArray(), $article->copyUrls),
            'changedAt' => $article->changedAt?->format(\DATE_ATOM),
            'changedBy' => $article->changedBy,
            'publishedOnTarget' => $this->publishedOnTarget($article),
            'access' => $access,
        ];
    }

    /**
     * The languages of the article on this system that are published (only for an article that an update would write into).
     *
     * @return list<string>
     */
    private function publishedOnTarget(PlanArticle $article): array
    {
        if (null === $article->targetId) {
            return [];
        }
        $target = $this->articleTarget->findArticle($article->targetId);
        if (null === $target) {
            return [];
        }

        $published = [];
        foreach ($target->dimensions as $dimension) {
            if ('live' === $dimension->stage) {
                $published[] = $dimension->locale;
            }
        }
        sort($published, \SORT_STRING);

        return array_values(array_unique($published));
    }

    /**
     * @return array<string, mixed>
     */
    private function media(ImportPlan $plan): array
    {
        $counts = [PlanMedium::REUSE => 0, PlanMedium::CREATE => 0, PlanMedium::NEW_VERSION => 0, PlanMedium::MISSING => 0];
        $folders = [];
        foreach ($plan->media as $medium) {
            ++$counts[$medium->action];
            if (PlanMedium::CREATE === $medium->action) {
                $folders[$medium->collection['type'] ?? 'system'] = true;
            }
        }
        $folderTypes = array_keys($folders);
        sort($folderTypes, \SORT_STRING);

        return [
            'total' => \count($plan->media),
            'reuse' => $counts[PlanMedium::REUSE],
            'create' => $counts[PlanMedium::CREATE],
            'newVersion' => $counts[PlanMedium::NEW_VERSION],
            'missing' => $counts[PlanMedium::MISSING],
            'folders' => $folderTypes,
        ];
    }

    /**
     * The categories that would be created, with the chain of their parents ("Tutorials › Beginners").
     *
     * @return list<array{label: string, path: string}>
     */
    private function categories(ImportPlan $plan): array
    {
        /** @var array<string, PlanCategory> $byRef */
        $byRef = [];
        foreach ($plan->categories as $category) {
            $byRef[$category->ref] = $category;
        }

        $result = [];
        foreach ($plan->categories as $category) {
            $chain = [$category->label()];
            for ($parent = $category->parentRef; null !== $parent && isset($byRef[$parent]) && \count($chain) < 20; $parent = $byRef[$parent]->parentRef) {
                array_unshift($chain, $byRef[$parent]->label());
            }
            $result[] = ['label' => $category->label(), 'path' => implode(' › ', $chain)];
        }

        return $result;
    }
}
