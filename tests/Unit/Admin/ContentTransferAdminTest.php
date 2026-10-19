<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Admin;

use Manuxi\SuluContentTransferBundle\Admin\ContentTransferAdmin;
use Manuxi\SuluContentTransferBundle\Security\ArticleSecurityContexts;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactory;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

class ContentTransferAdminTest extends TestCase
{
    public function testEveryArticleListGetsTheExportAndTheImportAction(): void
    {
        $views = $this->views();

        $this->admin([])->configureViews($views);

        foreach (['sulu_article.article.list_blog', 'sulu_article.article.list_faq'] as $name) {
            $this->assertSame(['sulu_content_transfer.export', 'sulu_content_transfer.import'], $this->actionTypes($views, $name), $name);
        }
        $this->assertSame([], $this->actionTypes($views, 'sulu_snippet.list'));
    }

    public function testListOfAGroupWithoutViewPermissionGetsNoExportAction(): void
    {
        $views = $this->views();
        $faq = ArticleAdmin::getArticleSecurityContext('faq');

        $this->admin([$faq.':'.PermissionTypes::VIEW])->configureViews($views);

        $this->assertSame(['sulu_content_transfer.export', 'sulu_content_transfer.import'], $this->actionTypes($views, 'sulu_article.article.list_blog'));
        $this->assertSame(['sulu_content_transfer.import'], $this->actionTypes($views, 'sulu_article.article.list_faq'));
    }

    public function testImportNeedsThePermissionToAddOrToEdit(): void
    {
        $blog = ArticleAdmin::getArticleSecurityContext('blog');
        $faq = ArticleAdmin::getArticleSecurityContext('faq');

        $views = $this->views();
        $this->admin([$blog.':'.PermissionTypes::ADD, $faq.':'.PermissionTypes::EDIT])->configureViews($views);
        $this->assertSame(['sulu_content_transfer.export', 'sulu_content_transfer.import'], $this->actionTypes($views, 'sulu_article.article.list_blog'));
        $this->assertSame(['sulu_content_transfer.export', 'sulu_content_transfer.import'], $this->actionTypes($views, 'sulu_article.article.list_faq'));

        $views = $this->views();
        $this->admin([$blog.':'.PermissionTypes::ADD, $blog.':'.PermissionTypes::EDIT])->configureViews($views);
        $this->assertSame(['sulu_content_transfer.export'], $this->actionTypes($views, 'sulu_article.article.list_blog'));
    }

    public function testActionsOfOtherAdminsStay(): void
    {
        $views = $this->views();
        $builder = $views->get('sulu_article.article.list_blog');
        if (!$builder instanceof ListViewBuilderInterface) {
            $this->fail('The article list must be a list view.');
        }
        $builder->addToolbarActions([new ToolbarAction('sulu_bulk_actions.actions')]);

        $this->admin([])->configureViews($views);

        $this->assertSame(['sulu_bulk_actions.actions', 'sulu_content_transfer.export', 'sulu_content_transfer.import'], $this->actionTypes($views, 'sulu_article.article.list_blog'));
    }

    public function testRunsAfterTheAdminsThatBuildTheLists(): void
    {
        $this->assertLessThan(0, ContentTransferAdmin::getPriority());
    }

    /**
     * @param list<string> $denied "<context>:<permission>" that the user does not have
     */
    private function admin(array $denied): ContentTransferAdmin
    {
        $checker = $this->createMock(SecurityCheckerInterface::class);
        $checker->method('hasPermission')->willReturnCallback(
            static fn (string $context, string $permission): bool => !\in_array($context.':'.$permission, $denied, true),
        );

        $provider = $this->createMock(GroupProviderInterface::class);
        $provider->method('getGroups')->willReturn([
            'blog' => new FormGroup('blog', 'Blog', ['blog-post']),
            'faq' => new FormGroup('faq', 'FAQ', ['faq-question']),
        ]);

        return new ContentTransferAdmin($checker, new ArticleSecurityContexts($provider));
    }

    private function views(): ViewCollection
    {
        $factory = new ViewBuilderFactory();
        $views = new ViewCollection();
        foreach (['sulu_article.article.list_blog', 'sulu_article.article.list_faq', 'sulu_snippet.list'] as $name) {
            $views->add($factory->createListViewBuilder($name, '/'.$name)->setResourceKey('articles')->setListKey('articles')->addListAdapters(['table']));
        }

        return $views;
    }

    /**
     * @return list<string>
     */
    private function actionTypes(ViewCollection $views, string $name): array
    {
        $actions = $views->get($name)->getView()->getOption('toolbarActions') ?? [];

        return array_values(array_map(static fn (ToolbarAction $action): string => $action->getType(), $actions));
    }
}
