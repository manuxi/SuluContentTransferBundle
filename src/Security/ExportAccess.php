<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Security;

use Manuxi\SuluContentTransferBundle\Exception\ExportDeniedException;
use Manuxi\SuluContentTransferBundle\Export\ArticleTemplates;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;

/**
 * Decides what the current user may export. Exporting needs the permission to view the article, checked per article
 * and language in the security context of its group, so nobody can take out of the system what the list would not show.
 *
 * Nothing is dropped silently: an explicitly requested language that one of the articles has but the user may not view
 * denies the whole export, and so does an article the user may not view in any language. Without a request for
 * languages, only the languages that are allowed for every selected article that has them are offered.
 */
class ExportAccess
{
    public function __construct(
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly ArticleSecurityContexts $contexts,
        private readonly ArticleTemplates $templates,
    ) {
    }

    /**
     * @param list<string> $uuids
     * @param list<string> $requestedLocales empty: every language the user may export
     *
     * @throws ExportDeniedException
     */
    public function resolve(array $uuids, array $requestedLocales): ExportScope
    {
        $uuids = array_values(array_unique(array_map('strtolower', $uuids)));
        $templates = $this->templates->templateKeys($uuids);

        /** @var array<string, array<string, bool>> $allowed article → language → may view */
        $allowed = [];
        $deniedLocales = [];
        $every = [];
        foreach ($templates as $uuid => $locales) {
            foreach ($locales as $locale => $templateKey) {
                $allowed[$uuid][$locale] = $this->mayView($templateKey, $locale);
                $every[$locale] = true;
                if (!$allowed[$uuid][$locale]) {
                    $deniedLocales[$locale] = true;
                }
            }
        }

        $denied = [];
        foreach ($allowed as $uuid => $locales) {
            if (!\in_array(true, $locales, true)) {
                $denied[] = $uuid;
            }
        }

        $available = array_values(array_filter(
            array_keys($every),
            static fn (string $locale): bool => !isset($deniedLocales[$locale]),
        ));
        sort($available, \SORT_STRING);

        if ([] === $requestedLocales) {
            // an empty list would mean "everything" to the exporter: never hand it one by accident
            if ([] !== $denied || ([] === $available && [] !== $templates)) {
                throw new ExportDeniedException([] !== $denied ? $denied : array_keys($templates));
            }

            return new ExportScope($available, $available);
        }

        foreach ($allowed as $uuid => $locales) {
            foreach ($requestedLocales as $locale) {
                if (isset($locales[$locale]) && !$locales[$locale]) {
                    $denied[] = $uuid;
                }
            }
        }

        if ([] !== $denied) {
            throw new ExportDeniedException(array_values(array_unique($denied)));
        }

        return new ExportScope($available, array_values(array_unique($requestedLocales)));
    }

    /**
     * The articles of the list that the user may view in at least one language.
     *
     * @param list<string> $uuids
     *
     * @return list<string>
     */
    public function filterViewable(array $uuids): array
    {
        $templates = $this->templates->templateKeys(array_values(array_unique(array_map('strtolower', $uuids))));

        $viewable = [];
        foreach ($templates as $uuid => $locales) {
            foreach ($locales as $locale => $templateKey) {
                if ($this->mayView($templateKey, $locale)) {
                    $viewable[] = $uuid;

                    break;
                }
            }
        }

        return $viewable;
    }

    private function mayView(string $templateKey, string $locale): bool
    {
        return $this->securityChecker->hasPermission(
            new SecurityCondition($this->contexts->forTemplateKey('' === $templateKey ? null : $templateKey), $locale),
            PermissionTypes::VIEW,
        );
    }
}
