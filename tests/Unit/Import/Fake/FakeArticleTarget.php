<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

use Manuxi\SuluContentTransferBundle\Import\Target\ArticleTarget;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetArticle;

class FakeArticleTarget implements ArticleTarget
{
    /** @var list<string> */
    public array $templates = ['tutorial'];

    /** @var array<string, TargetArticle> */
    public array $articles = [];

    /** @var array<string, string> "locale|slug" => owner */
    public array $routes = [];

    public function templateKeys(): array
    {
        return $this->templates;
    }

    public function findArticle(string $uuid): ?TargetArticle
    {
        return $this->articles[$uuid] ?? null;
    }

    public function routeOwner(string $locale, string $slug, ?string $excludeArticleUuid): ?string
    {
        $owner = $this->routes[$locale.'|'.$slug] ?? null;
        if (null !== $excludeArticleUuid && 'articles:'.$excludeArticleUuid === $owner) {
            return null;
        }

        return $owner;
    }
}
