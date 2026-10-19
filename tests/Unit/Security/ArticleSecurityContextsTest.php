<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Security;

use Manuxi\SuluContentTransferBundle\Security\ArticleSecurityContexts;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;

class ArticleSecurityContextsTest extends TestCase
{
    public function testGroupsHaveTheirOwnContext(): void
    {
        $contexts = $this->contexts([
            'blog' => new FormGroup('blog', 'Blog', ['post']),
            'faq' => new FormGroup('faq', 'FAQ', ['question']),
        ]);

        $this->assertSame(ArticleAdmin::getArticleSecurityContext('blog'), $contexts->forListView('sulu_article.article.list_blog'));
        $this->assertSame(ArticleAdmin::getArticleSecurityContext('faq'), $contexts->forTemplateKey('question'));
    }

    public function testAllContextsAreTheBaseContextAndOneForEachGroup(): void
    {
        $contexts = $this->contexts([
            GroupProviderInterface::DEFAULT_GROUP => new FormGroup(GroupProviderInterface::DEFAULT_GROUP, 'Default', ['plain']),
            'blog' => new FormGroup('blog', 'Blog', ['post']),
            'faq' => new FormGroup('faq', 'FAQ', ['question']),
        ]);

        $this->assertSame(
            [ArticleAdmin::SECURITY_CONTEXT, ArticleAdmin::getArticleSecurityContext('blog'), ArticleAdmin::getArticleSecurityContext('faq')],
            $contexts->all(),
        );
        $this->assertSame([ArticleAdmin::SECURITY_CONTEXT], $this->contexts(['blog' => new FormGroup('blog', 'Blog', ['post'])])->all());
    }

    public function testDefaultGroupAndSingleGroupUseTheBaseContext(): void
    {
        $several = $this->contexts([
            GroupProviderInterface::DEFAULT_GROUP => new FormGroup(GroupProviderInterface::DEFAULT_GROUP, 'Default', ['plain']),
            'blog' => new FormGroup('blog', 'Blog', ['post']),
        ]);
        $this->assertSame(ArticleAdmin::SECURITY_CONTEXT, $several->forTemplateKey('plain'));
        $this->assertSame(ArticleAdmin::SECURITY_CONTEXT, $several->forListView('sulu_article.article.list_'.GroupProviderInterface::DEFAULT_GROUP));

        $single = $this->contexts(['blog' => new FormGroup('blog', 'Blog', ['post'])]);
        $this->assertSame(ArticleAdmin::SECURITY_CONTEXT, $single->forTemplateKey('post'));
        $this->assertSame(ArticleAdmin::SECURITY_CONTEXT, $single->forListView('sulu_article.article.list_blog'));
    }

    public function testUnknownTemplateFallsBackToTheBaseContext(): void
    {
        $contexts = $this->contexts([
            'blog' => new FormGroup('blog', 'Blog', ['post']),
            'faq' => new FormGroup('faq', 'FAQ', ['question']),
        ]);

        $this->assertSame(ArticleAdmin::SECURITY_CONTEXT, $contexts->forTemplateKey('unknown'));
        $this->assertSame(ArticleAdmin::SECURITY_CONTEXT, $contexts->forTemplateKey(null));
    }

    public function testOtherViewsAreNoArticleLists(): void
    {
        $contexts = $this->contexts(['blog' => new FormGroup('blog', 'Blog', ['post'])]);

        $this->assertNull($contexts->forListView('sulu_snippet.list'));
        $this->assertNull($contexts->forListView('sulu_article.article.list'));
    }

    /**
     * @param array<string, FormGroup> $groups
     */
    private function contexts(array $groups): ArticleSecurityContexts
    {
        $provider = $this->createMock(GroupProviderInterface::class);
        $provider->method('getGroups')->with(ArticleInterface::TEMPLATE_TYPE)->willReturn($groups);

        return new ArticleSecurityContexts($provider);
    }
}
