<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Security;

use Manuxi\SuluContentTransferBundle\DependencyInjection\SuluContentTransferExtension;
use Manuxi\SuluContentTransferBundle\Exception\ImportDeniedException;
use Manuxi\SuluContentTransferBundle\Export\ArticleTemplates;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;

/**
 * Decides what the current user may import. Per article and language, in the security context of the group of the
 * article: creating (and copying) needs the permission to add, updating the permission to edit, and publishing ("same
 * status as in the source") the permission to publish. Images the article needs have to be addable to the target folder
 * (and the file version of an existing image editable).
 *
 * The admin shows an article the user may not write as not importable, with the reason. The server checks again when the
 * import is carried out: an article that is denied by default is left out, an article the user explicitly asked for is
 * refused and nothing is written.
 */
class ImportAccess
{
    public function __construct(
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly ArticleSecurityContexts $contexts,
        private readonly MediaCollectionLookup $collections,
        private readonly SystemCollectionManagerInterface $systemCollections,
        private readonly ArticleTemplates $presentTemplates,
    ) {
    }

    /**
     * Whether the user may import at all: add or edit articles in at least one article group. The analysis of a package
     * (which tells what exists on this system) is refused to everybody else.
     *
     * @throws ImportDeniedException
     */
    public function assertMayImport(): void
    {
        foreach ($this->contexts->all() as $context) {
            $condition = new SecurityCondition($context);
            if ($this->securityChecker->hasPermission($condition, PermissionTypes::ADD)
                || $this->securityChecker->hasPermission($condition, PermissionTypes::EDIT)
            ) {
                return;
            }
        }

        throw new ImportDeniedException([]);
    }

    /**
     * What the user may do with each article of the plan.
     *
     * @return array<string, ArticleImportAccess> by article UUID in the package
     */
    public function describe(ImportPlan $plan): array
    {
        $collectionCache = [];
        $access = [];
        $existing = $this->presentTemplates->templateKeys(array_values(array_filter(array_map(
            static fn (PlanArticle $article): ?string => $article->targetId,
            $plan->articles,
        ))));
        foreach ($plan->articles as $article) {
            $locales = $article->importLocales();
            $context = $this->contexts->forTemplateKey($article->templateKey);

            $mediaCreate = true;
            $mediaTexts = true;
            foreach ($plan->media as $medium) {
                if (!\in_array($article->uuid, $medium->usedBy, true)) {
                    continue;
                }
                if (PlanMedium::CREATE === $medium->action) {
                    $collection = $this->targetCollection($medium);
                    $mediaCreate = $mediaCreate && (null !== $collection && $this->mayInCollection($collection, PermissionTypes::ADD, $collectionCache));
                } elseif (PlanMedium::NEW_VERSION === $medium->action) {
                    $mediaCreate = $mediaCreate && $this->mayEditMedium($medium, $collectionCache);
                } elseif (PlanMedium::REUSE === $medium->action) {
                    $mediaTexts = $mediaTexts && $this->mayEditMedium($medium, $collectionCache);
                }
            }

            // Updating writes into the article that exists here, whatever template the package says: the group it
            // is in now counts as well, or "edit" in one group would be enough to overwrite an article of any other.
            $present = null !== $article->targetId ? ($existing[strtolower($article->targetId)] ?? []) : [];

            $access[$article->uuid] = new ArticleImportAccess(
                $this->allowed($context, $locales, PermissionTypes::ADD),
                $this->allowedInPresentGroup($present, $this->allowed($context, $locales, PermissionTypes::EDIT), PermissionTypes::EDIT),
                $this->allowedInPresentGroup($present, $this->allowed($context, $locales, PermissionTypes::LIVE), PermissionTypes::LIVE),
                $mediaCreate,
                $mediaTexts,
            );
        }

        return $access;
    }

    /**
     * The decisions that are carried out: articles the user may not write and did not ask for by name are set to "skip".
     *
     * @throws ImportDeniedException when the decisions name an action for an article the user may not write
     */
    public function enforce(ImportPlan $plan, ImportDecisions $decisions): ImportDecisions
    {
        $access = $this->describe($plan);
        $actions = $decisions->actions;
        $denied = [];

        foreach ($plan->articles as $article) {
            $explicit = isset($decisions->actions[$article->uuid]);
            $action = $decisions->actions[$article->uuid] ?? $article->defaultAction;
            // unknown or disallowed actions are refused by the decisions themselves
            if (!\in_array($action, ArticleAction::ALL, true) || !\in_array($action, $article->allowedActions, true)) {
                continue;
            }

            $locales = $decisions->localesFor($article);
            $reason = $access[$article->uuid]->denial(
                $action,
                $locales,
                $decisions->keepStatus ? self::publishedLocales($article, $locales) : [],
                $decisions->overwriteMediaMeta,
            );
            if (null === $reason) {
                continue;
            }

            if ($explicit) {
                $denied[$article->uuid] = $reason;
            } else {
                $actions[$article->uuid] = ArticleAction::SKIP;
            }
        }

        if ([] !== $denied) {
            throw new ImportDeniedException($denied);
        }

        return new ImportDecisions($actions, $decisions->locales, $decisions->keepStatus, $decisions->overwriteMediaMeta);
    }

    /**
     * The languages among $locales that the source had published and that "same status as in the source" would publish.
     *
     * @param list<string> $locales
     *
     * @return list<string>
     */
    public static function publishedLocales(PlanArticle $article, array $locales): array
    {
        $published = [];
        foreach ($locales as $locale) {
            $planLocale = $article->locales[$locale] ?? null;
            if (null !== $planLocale && $planLocale->published && 'live' === $planLocale->stage) {
                $published[] = $locale;
            }
        }

        return $published;
    }

    /**
     * Of the languages the user may write according to the template of the package, those that the user may also write
     * in the group of the article that exists on this system (language → template key there; empty: no such article).
     *
     * @param array<string, string> $present
     * @param list<string>          $locales
     *
     * @return list<string>
     */
    private function allowedInPresentGroup(array $present, array $locales, string $permission): array
    {
        if ([] === $present) {
            return $locales;
        }

        $fallback = reset($present);

        return array_values(array_filter($locales, function (string $locale) use ($present, $fallback, $permission): bool {
            $context = $this->contexts->forTemplateKey($present[$locale] ?? $fallback);

            return $this->securityChecker->hasPermission(new SecurityCondition($context, $locale), $permission);
        }));
    }

    /**
     * @param list<string> $locales
     *
     * @return list<string>
     */
    private function allowed(string $context, array $locales, string $permission): array
    {
        return array_values(array_filter(
            $locales,
            fn (string $locale): bool => $this->securityChecker->hasPermission(new SecurityCondition($context, $locale), $permission),
        ));
    }

    /**
     * @return array{id: int, system: bool}|null
     */
    private function targetCollection(PlanMedium $medium): ?array
    {
        $collection = $medium->collection ?? ['type' => 'system', 'key' => SuluContentTransferExtension::SYSTEM_COLLECTION_KEY];
        if ('collection' === $collection['type'] && isset($collection['id'])) {
            return $this->collections->collection((int) $collection['id']);
        }

        $id = $this->systemCollections->getSystemCollection((string) ($collection['key'] ?? SuluContentTransferExtension::SYSTEM_COLLECTION_KEY));

        return ['id' => (int) $id, 'system' => true];
    }

    /**
     * @param array<string, bool> $cache
     */
    private function mayEditMedium(PlanMedium $medium, array &$cache): bool
    {
        if (null === $medium->targetId) {
            return false;
        }
        $collection = $this->collections->collectionOfMedium($medium->targetId);

        return null !== $collection && $this->mayInCollection($collection, PermissionTypes::EDIT, $cache);
    }

    /**
     * @param array{id: int, system: bool} $collection
     * @param array<string, bool>          $cache
     */
    private function mayInCollection(array $collection, string $permission, array &$cache): bool
    {
        $key = $collection['id'].':'.$permission;

        return $cache[$key] ??= $this->securityChecker->hasPermission(
            new SecurityCondition(MediaAdmin::SECURITY_CONTEXT, null, Collection::class, $collection['id']),
            $permission,
        );
    }
}
