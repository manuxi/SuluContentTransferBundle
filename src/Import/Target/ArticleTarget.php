<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

/**
 * What the import analysis asks this system about articles and their addresses.
 */
interface ArticleTarget
{
    /**
     * @return list<string> keys of the article templates this system has
     */
    public function templateKeys(): array;

    public function findArticle(string $uuid): ?TargetArticle;

    /**
     * Who uses an address in a language, ignoring the redirects of the article given in $excludeArticleUuid.
     *
     * @return ?string "articles:<uuid>", "pages:<uuid>" or "<resourceKey>:<id>" of any other user, null if the address is free
     */
    public function routeOwner(string $locale, string $slug, ?string $excludeArticleUuid): ?string;
}
