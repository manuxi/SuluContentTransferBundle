<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Execute;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;

/**
 * What the person who imports decided after seeing the plan.
 */
final class ImportDecisions
{
    /**
     * @param array<string, string> $actions            action per article UUID of the package (create, update, copy, skip);
     *                                                  articles without an entry get the action the plan suggests
     * @param list<string>|null     $locales            languages to import; null: every language the plan accepts
     * @param bool                  $keepStatus         publish the languages that were published on the source (default: everything
     *                                                  stays a draft, nothing goes live by itself)
     * @param bool                  $overwriteMediaMeta overwrite titles, descriptions and copyright of images that already exist
     */
    public function __construct(
        public readonly array $actions = [],
        public readonly ?array $locales = null,
        public readonly bool $keepStatus = false,
        public readonly bool $overwriteMediaMeta = false,
    ) {
    }

    /**
     * The action for an article; refuses one the plan does not allow for it.
     *
     * @throws ContentTransferException
     */
    public function actionFor(PlanArticle $article): string
    {
        $action = $this->actions[$article->uuid] ?? $article->defaultAction;
        if (!\in_array($action, ArticleAction::ALL, true)) {
            throw new ContentTransferException(\sprintf('The action "%s" for the article %s is unknown.', $action, $article->uuid), 'import.action_unknown');
        }
        if (!\in_array($action, $article->allowedActions, true)) {
            throw new ContentTransferException(
                \sprintf('The action "%s" is not allowed for the article %s (allowed: %s).', $action, $article->uuid, implode(', ', $article->allowedActions)),
                'import.action_not_allowed',
            );
        }

        return $action;
    }

    /**
     * Checks the decisions against the plan, before anything is written.
     *
     * @throws ContentTransferException
     */
    public function validate(ImportPlan $plan): void
    {
        foreach (array_keys($this->actions) as $uuid) {
            if (null === $plan->article((string) $uuid)) {
                throw new ContentTransferException(\sprintf('The package contains no article %s.', $uuid), 'import.article_unknown');
            }
        }
        foreach ($plan->articles as $article) {
            $this->actionFor($article);
        }
    }

    /**
     * @return list<string> languages of the article that are imported with these decisions
     */
    public function localesFor(PlanArticle $article): array
    {
        $locales = $article->importLocales();
        if (null === $this->locales) {
            return $locales;
        }

        return array_values(array_filter($locales, fn (string $locale): bool => \in_array($locale, $this->locales, true)));
    }
}
