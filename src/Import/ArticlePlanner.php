<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;
use Manuxi\SuluContentTransferBundle\Format\ArticleDimension;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\ManifestItem;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleState;
use Manuxi\SuluContentTransferBundle\Import\Plan\Notice;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanLocale;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanUrl;
use Manuxi\SuluContentTransferBundle\Import\Target\ArticleTarget;
use Manuxi\SuluContentTransferBundle\Import\Target\ReferenceTarget;
use Manuxi\SuluContentTransferBundle\Import\Target\SystemTarget;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetArticle;
use Manuxi\SuluContentTransferBundle\Repository\TransferMappings;

/**
 * Plans one article of the package: which article of this system it belongs to (Plan E4), whether that one was changed
 * here in the meantime (E10), which languages this system takes (E8, E11), which addresses they get and what is
 * suggested and allowed.
 *
 * Matching, in this order: (1) the mapping table knows this article of this source; (2) an article with the same UUID
 * exists; (3) none: the article is new. A package from this very system matches by UUID only and may only be
 * imported as a copy.
 *
 * "Changed here" means:
 *  - matched through the mapping table: any version of the article was changed after the end of the last import;
 *  - matched by UUID without an earlier import: a version of the same language and stage was changed after the
 *    version in the package.
 */
final class ArticlePlanner
{
    /** Timestamps are stored to the second; a difference of this size is not a change. */
    public const CHANGE_TOLERANCE_SECONDS = 1;

    public function __construct(
        private readonly ArticleTarget $articles,
        private readonly SystemTarget $system,
        private readonly ReferenceTarget $references,
        private readonly TransferMappings $mappings,
    ) {
    }

    public function plan(ArticleEntry $entry, ManifestItem $item, AnalysisContext $context): ArticlePlanResult
    {
        $notices = [];

        $unknownTemplates = $this->unknownTemplates($entry, $item, $context);
        if ([] !== $unknownTemplates) {
            $notices[] = new Notice(
                Notice::ERROR,
                'template.missing',
                \sprintf('The template "%s" does not exist on this system. Deploy the code with the template first, then import.', implode('", "', $unknownTemplates)),
                $entry->uuid,
                null,
                ['templates' => implode(', ', $unknownTemplates)],
            );

            return new ArticlePlanResult($this->blocked($entry, $item, PlanLocale::REASON_TEMPLATE_MISSING), $notices);
        }

        [$target, $mapping] = $this->match($entry, $context, $notices);
        [$state, $changedAt, $changedBy] = $this->state($entry, $target, $mapping, $notices);

        $locales = [];
        $directUrls = [];
        $copyUrls = [];
        $webspaceNotes = [];
        $firstRequested = null;
        $firstTarget = null;

        foreach ($entry->dimensions as $locale => $dimension) {
            $requested = $this->requestedWebspace($dimension);
            $webspace = null !== $requested && \in_array($requested, $this->system->webspaceKeys(), true) ? $requested : $this->system->defaultWebspace();
            if (null !== $requested && $webspace !== $requested && !isset($webspaceNotes[$requested])) {
                $webspaceNotes[$requested] = true;
                $notices[] = new Notice(
                    Notice::WARNING,
                    'webspace.fallback',
                    \sprintf('The webspace "%s" does not exist on this system. The article gets %s.', $requested, null !== $webspace ? '"'.$webspace.'"' : 'no webspace'),
                    $entry->uuid,
                    null,
                    ['requested' => $requested, 'webspace' => $webspace],
                );
            }
            $firstRequested ??= $requested;
            $firstTarget ??= $webspace;

            if (null === $webspace || !\in_array($locale, $this->system->localesOf($webspace), true)) {
                $locales[] = new PlanLocale($locale, false, $dimension->stage, $dimension->published, PlanLocale::REASON_LOCALE_UNKNOWN);
                $notices[] = new Notice(
                    Notice::WARNING,
                    'locale.unknown',
                    \sprintf('The language "%s" is not configured on this system%s and is skipped.', $locale, null !== $webspace ? ' for the webspace "'.$webspace.'"' : ''),
                    $entry->uuid,
                    $locale,
                    ['webspace' => $webspace],
                );

                continue;
            }

            $urls = $this->urls($dimension, $locale, $target?->uuid, $context);
            if (null === $urls) {
                $locales[] = new PlanLocale($locale, false, $dimension->stage, $dimension->published, PlanLocale::REASON_URL_PARENT_MISSING);
                $notices[] = new Notice(
                    Notice::ERROR,
                    'url.parent_missing',
                    \sprintf('The page under which the address of the language "%s" lives was not found on this system. The language is skipped.', $locale),
                    $entry->uuid,
                    $locale,
                );

                continue;
            }

            $locales[] = new PlanLocale($locale, true, $dimension->stage, $dimension->published);
            $directUrls[$locale] = $urls['direct'];
            $copyUrls[$locale] = $urls['copy'];
        }

        $importable = array_filter($locales, static fn (PlanLocale $locale): bool => $locale->import);
        if ([] === $importable) {
            $notices[] = new Notice(Notice::ERROR, 'article.no_language', 'No language of the article can be imported on this system.', $entry->uuid);

            return new ArticlePlanResult($this->blockedWith($entry, $item, $locales, $firstRequested, $firstTarget), $notices);
        }

        [$default, $allowed] = $this->actions($context->sameInstance, $target, $state);

        $claimFrom = match ($default) {
            ArticleAction::CREATE, ArticleAction::UPDATE => $directUrls,
            ArticleAction::COPY => $copyUrls,
            default => [],
        };
        foreach ($claimFrom as $locale => $url) {
            if (null !== $url->proposed) {
                $context->claim($locale, $url->proposed);
            }
        }
        foreach ($claimFrom as $locale => $url) {
            if ($url->isTaken()) {
                $notices[] = new Notice(
                    Notice::WARNING,
                    'url.taken',
                    \sprintf('The address %s is in use%s. The article gets %s instead.', $url->requested, null !== $url->takenBy ? ' ('.$url->takenBy.')' : '', $url->proposed),
                    $entry->uuid,
                    $locale,
                    ['requested' => $url->requested, 'proposed' => $url->proposed, 'takenBy' => $url->takenBy],
                );
            }
        }

        return new ArticlePlanResult(
            new PlanArticle(
                $entry->uuid,
                $item->titles,
                $item->templateKey,
                $state,
                null === $target ? null : (null !== $mapping ? PlanArticle::MATCH_MAPPING : PlanArticle::MATCH_UUID),
                $target?->uuid,
                $default,
                $allowed,
                $locales,
                $directUrls,
                $copyUrls,
                $firstRequested,
                $firstTarget,
                $changedAt,
                $changedBy,
            ),
            $notices,
        );
    }

    /**
     * @return list<string> template keys of the article that this system does not have
     */
    private function unknownTemplates(ArticleEntry $entry, ManifestItem $item, AnalysisContext $context): array
    {
        $unknown = [];
        foreach ($entry->dimensions as $dimension) {
            $template = $dimension->data['template'] ?? $item->templateKey;
            if (!\is_string($template) || '' === $template) {
                $unknown['(none)'] = true;
            } elseif (!$context->hasTemplate($template)) {
                $unknown[$template] = true;
            }
        }

        return array_keys($unknown);
    }

    /**
     * @param list<Notice> $notices
     *
     * @return array{0: ?TargetArticle, 1: ?TransferMapping}
     */
    private function match(ArticleEntry $entry, AnalysisContext $context, array &$notices): array
    {
        if (!$context->sameInstance) {
            $mapping = $this->mappings->findMapping($context->sourceInstance, 'articles', $entry->uuid);
            if (null !== $mapping) {
                $target = $this->articles->findArticle($mapping->getTargetId());
                if (null !== $target) {
                    return [$target, $mapping];
                }
                $notices[] = new Notice(
                    Notice::INFO,
                    'article.mapping_stale',
                    'The article from an earlier import no longer exists on this system.',
                    $entry->uuid,
                );
            }
        }

        return [$this->articles->findArticle($entry->uuid), null];
    }

    /**
     * @param list<Notice> $notices
     *
     * @return array{0: string, 1: ?\DateTimeImmutable, 2: ?string}
     */
    private function state(ArticleEntry $entry, ?TargetArticle $target, ?TransferMapping $mapping, array &$notices): array
    {
        if (null === $target) {
            return [ArticleState::NEW, null, null];
        }

        $changed = null;
        if (null !== $mapping) {
            $latest = $target->latest();
            $limit = $mapping->getImportedAt()->modify('+'.self::CHANGE_TOLERANCE_SECONDS.' seconds');
            if (null !== $latest && $latest->changed > $limit) {
                $changed = $latest;
            }

            $sourceChanged = $mapping->getSourceChanged();
            if (null !== $sourceChanged && $entry->changed <= $sourceChanged) {
                $notices[] = new Notice(Notice::INFO, 'article.package_not_newer', 'The package holds no newer content than the last import.', $entry->uuid);
            }
        } else {
            foreach ($entry->dimensions as $dimension) {
                $version = $target->dimension($dimension->locale, $dimension->stage);
                if (null === $version) {
                    continue;
                }
                $limit = $this->changedOf($dimension, $entry)->modify('+'.self::CHANGE_TOLERANCE_SECONDS.' seconds');
                if ($version->changed > $limit && (null === $changed || $version->changed > $changed->changed)) {
                    $changed = $version;
                }
            }
        }

        if (null === $changed) {
            return [ArticleState::UNCHANGED, null, null];
        }

        return [ArticleState::CHANGED, $changed->changed, $changed->changedBy];
    }

    private function changedOf(ArticleDimension $dimension, ArticleEntry $entry): \DateTimeImmutable
    {
        $value = $dimension->data['changed'] ?? null;
        if (\is_string($value)) {
            $date = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);
            if (false !== $date) {
                return $date;
            }
        }

        return $entry->changed;
    }

    private function requestedWebspace(ArticleDimension $dimension): ?string
    {
        $webspace = $dimension->data['mainWebspace'] ?? null;

        return \is_string($webspace) && '' !== $webspace ? $webspace : null;
    }

    /**
     * The address of one language for "create/update" and for "copy". Null if the address lives under a page that
     * this system does not have.
     *
     * @return array{direct: PlanUrl, copy: PlanUrl}|null
     */
    private function urls(ArticleDimension $dimension, string $locale, ?string $targetUuid, AnalysisContext $context): ?array
    {
        $url = $dimension->data['url'] ?? null;
        $parentPage = null;
        $requested = null;

        if (\is_array($url) && \is_array($url['page'] ?? null) && \is_string($url['page']['uuid'] ?? null) && '' !== $url['page']['uuid']) {
            $resolution = $context->resolver->resolve('pages', $url['page']['uuid']);
            $page = PlanResolution::MAPPED === $resolution->status ? $this->references->page((string) $resolution->targetId) : null;
            $base = $page?->urls[$locale] ?? null;
            if (null === $page || null === $base) {
                return null;
            }

            $parentPage = $page->uuid;
            $suffix = \is_string($url['suffix'] ?? null) ? $url['suffix'] : '';
            $requested = rtrim($base, '/').'/'.ltrim($suffix, '/');
        } elseif (\is_string($url) && '' !== $url) {
            $requested = $url;
        }

        if (null === $requested) {
            $none = new PlanUrl(null, null);

            return ['direct' => $none, 'copy' => $none];
        }

        return [
            'direct' => $this->checkedUrl($requested, $parentPage, $locale, $targetUuid, $context),
            'copy' => $this->checkedUrl($requested, $parentPage, $locale, null, $context),
        ];
    }

    private function checkedUrl(string $requested, ?string $parentPage, string $locale, ?string $excludeUuid, AnalysisContext $context): PlanUrl
    {
        $articles = $this->articles;
        $owner = static function (string $slug) use ($articles, $locale, $excludeUuid, $context): ?string {
            if ($context->isClaimed($locale, $slug)) {
                return 'package';
            }

            return $articles->routeOwner($locale, $slug, $excludeUuid);
        };

        $proposed = UniqueSlug::propose($requested, static fn (string $slug): bool => null !== $owner($slug));

        return new PlanUrl($requested, $proposed, $owner($requested), $parentPage);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function actions(bool $sameInstance, ?TargetArticle $target, string $state): array
    {
        if ($sameInstance) {
            return [ArticleAction::COPY, [ArticleAction::COPY, ArticleAction::SKIP]];
        }
        if (null === $target) {
            return [ArticleAction::CREATE, [ArticleAction::CREATE, ArticleAction::COPY, ArticleAction::SKIP]];
        }
        if (ArticleState::CHANGED === $state) {
            return [ArticleAction::SKIP, [ArticleAction::SKIP, ArticleAction::UPDATE, ArticleAction::COPY]];
        }

        return [ArticleAction::UPDATE, [ArticleAction::UPDATE, ArticleAction::COPY, ArticleAction::SKIP]];
    }

    private function blocked(ArticleEntry $entry, ManifestItem $item, string $reason): PlanArticle
    {
        $locales = [];
        foreach ($entry->dimensions as $dimension) {
            $locales[] = new PlanLocale($dimension->locale, false, $dimension->stage, $dimension->published, $reason);
        }

        return $this->blockedWith($entry, $item, $locales, null, null);
    }

    /**
     * @param list<PlanLocale> $locales
     */
    private function blockedWith(ArticleEntry $entry, ManifestItem $item, array $locales, ?string $mainWebspace, ?string $targetWebspace): PlanArticle
    {
        return new PlanArticle(
            $entry->uuid,
            $item->titles,
            $item->templateKey,
            ArticleState::BLOCKED,
            null,
            null,
            ArticleAction::SKIP,
            [ArticleAction::SKIP],
            $locales,
            [],
            [],
            $mainWebspace,
            $targetWebspace,
        );
    }
}
