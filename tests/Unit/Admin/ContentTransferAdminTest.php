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
    private const TRANSFER = 'sulu_content_transfer.transfer';

    public function testDropdownTakesTheNameOfTheActionAndTheOptionsOfTheEntries(): void
    {
        $views = $this->views();

        $this->admin([])->configureViews($views);

        $this->assertSame('sulu_content_transfer.transfer', ContentTransferAdmin::TRANSFER_TOOLBAR_ACTION);
        $this->assertSame([['csv', 'export', 'import']], array_values($this->entries($views, 'sulu_article.article.list_blog')));
    }

    public function testDropdownReplacesTheCsvExportAtItsPosition(): void
    {
        $views = $this->views();

        $this->admin([])->configureViews($views);

        foreach (['sulu_article.article.list_blog', 'sulu_article.article.list_faq'] as $name) {
            $this->assertSame(['sulu_admin.add', 'sulu_admin.delete', self::TRANSFER, 'sulu_bulk_actions.actions'], $this->actionTypes($views, $name), $name);
            $this->assertSame(['csv', 'export', 'import'], $this->entries($views, $name)[self::TRANSFER], $name);
        }
        $this->assertSame([], $this->actionTypes($views, 'sulu_snippet.list'));
    }

    public function testDropdownGetsAppendedWhenTheListHasNoCsvExport(): void
    {
        $views = $this->views(csv: false);

        $this->admin([])->configureViews($views);

        $this->assertSame(['sulu_admin.add', 'sulu_admin.delete', 'sulu_bulk_actions.actions', self::TRANSFER], $this->actionTypes($views, 'sulu_article.article.list_blog'));
        $this->assertSame(['export', 'import'], $this->entries($views, 'sulu_article.article.list_blog')[self::TRANSFER]);
    }

    public function testDropdownGetsAppendedToAListWithoutAnyActions(): void
    {
        $views = $this->views(csv: false, others: false);

        $this->admin([])->configureViews($views);

        $this->assertSame([self::TRANSFER], $this->actionTypes($views, 'sulu_article.article.list_blog'));
    }

    public function testCsvExportStaysAButtonWithoutTheMerge(): void
    {
        $views = $this->views();

        $this->admin([], mergeCsvExport: false)->configureViews($views);

        $name = 'sulu_article.article.list_blog';
        $this->assertSame(['sulu_admin.add', 'sulu_admin.delete', 'sulu_admin.export', 'sulu_bulk_actions.actions', self::TRANSFER], $this->actionTypes($views, $name));
        $this->assertSame(['export', 'import'], $this->entries($views, $name)[self::TRANSFER]);
    }

    public function testButtonsKeepTheBehaviorOfVersion10(): void
    {
        $views = $this->views();

        $this->admin([], toolbar: 'buttons')->configureViews($views);

        foreach (['sulu_article.article.list_blog', 'sulu_article.article.list_faq'] as $name) {
            $this->assertSame(
                ['sulu_admin.add', 'sulu_admin.delete', 'sulu_admin.export', 'sulu_bulk_actions.actions', 'sulu_content_transfer.export', 'sulu_content_transfer.import'],
                $this->actionTypes($views, $name),
                $name,
            );
        }
        $this->assertSame([], $this->actionTypes($views, 'sulu_snippet.list'));
    }

    public function testButtonsOnlyOfferWhatTheUserMayUse(): void
    {
        $views = $this->views(csv: false, others: false);
        $faq = ArticleAdmin::getArticleSecurityContext('faq');

        $this->admin([$faq.':'.PermissionTypes::VIEW], toolbar: 'buttons')->configureViews($views);

        $this->assertSame(['sulu_content_transfer.export', 'sulu_content_transfer.import'], $this->actionTypes($views, 'sulu_article.article.list_blog'));
        $this->assertSame(['sulu_content_transfer.import'], $this->actionTypes($views, 'sulu_article.article.list_faq'));
    }

    public function testListOfAGroupWithoutViewPermissionGetsNoExportEntry(): void
    {
        $faq = ArticleAdmin::getArticleSecurityContext('faq');

        // without the view permission Sulu has no CSV export in the list either
        $views = $this->views(csvFor: ['sulu_article.article.list_blog']);
        $this->admin([$faq.':'.PermissionTypes::VIEW])->configureViews($views);

        $this->assertSame(['csv', 'export', 'import'], $this->entries($views, 'sulu_article.article.list_blog')[self::TRANSFER]);
        $this->assertSame(['import'], $this->entries($views, 'sulu_article.article.list_faq')[self::TRANSFER]);
        $this->assertSame(['sulu_admin.add', 'sulu_admin.delete', 'sulu_bulk_actions.actions', self::TRANSFER], $this->actionTypes($views, 'sulu_article.article.list_faq'));
    }

    public function testImportNeedsThePermissionToAddOrToEdit(): void
    {
        $blog = ArticleAdmin::getArticleSecurityContext('blog');
        $faq = ArticleAdmin::getArticleSecurityContext('faq');

        $views = $this->views();
        $this->admin([$blog.':'.PermissionTypes::ADD, $faq.':'.PermissionTypes::EDIT])->configureViews($views);
        $this->assertSame(['csv', 'export', 'import'], $this->entries($views, 'sulu_article.article.list_blog')[self::TRANSFER]);
        $this->assertSame(['csv', 'export', 'import'], $this->entries($views, 'sulu_article.article.list_faq')[self::TRANSFER]);

        $views = $this->views();
        $this->admin([$blog.':'.PermissionTypes::ADD, $blog.':'.PermissionTypes::EDIT])->configureViews($views);
        $this->assertSame(['csv', 'export'], $this->entries($views, 'sulu_article.article.list_blog')[self::TRANSFER]);
    }

    public function testWithoutAnyPermissionTheListStaysAsItIs(): void
    {
        $blog = ArticleAdmin::getArticleSecurityContext('blog');

        $views = $this->views();
        $this->admin([
            $blog.':'.PermissionTypes::VIEW,
            $blog.':'.PermissionTypes::ADD,
            $blog.':'.PermissionTypes::EDIT,
        ])->configureViews($views);

        // no dropdown without an entry; Sulu's own CSV button is not touched
        $this->assertSame(['sulu_admin.add', 'sulu_admin.delete', 'sulu_admin.export', 'sulu_bulk_actions.actions'], $this->actionTypes($views, 'sulu_article.article.list_blog'));
    }

    public function testWithoutAnyPermissionButtonsAreNotOfferedEither(): void
    {
        $blog = ArticleAdmin::getArticleSecurityContext('blog');

        $views = $this->views(csv: false, others: false);
        $this->admin([
            $blog.':'.PermissionTypes::VIEW,
            $blog.':'.PermissionTypes::ADD,
            $blog.':'.PermissionTypes::EDIT,
        ], toolbar: 'buttons')->configureViews($views);

        $this->assertSame([], $this->actionTypes($views, 'sulu_article.article.list_blog'));
    }

    public function testActionsOfOtherAdminsStay(): void
    {
        $views = $this->views(csv: false, others: false);
        $builder = $views->get('sulu_article.article.list_blog');
        if (!$builder instanceof ListViewBuilderInterface) {
            $this->fail('The article list must be a list view.');
        }
        $builder->addToolbarActions([new ToolbarAction('sulu_bulk_actions.actions')]);

        $this->admin([])->configureViews($views);

        $this->assertSame(['sulu_bulk_actions.actions', self::TRANSFER], $this->actionTypes($views, 'sulu_article.article.list_blog'));
    }

    public function testBuilderWithAnUnreachableViewGetsTheDropdownAppendedAndKeepsTheCsvButton(): void
    {
        $builder = $this->createMock(ListViewBuilderInterface::class);
        $builder->method('getName')->willReturn('sulu_article.article.list_blog');
        $builder->expects($this->once())->method('addToolbarActions')->with($this->callback(
            static fn (array $actions): bool => 1 === \count($actions)
                && self::TRANSFER === $actions[0]->getType()
                && ['export', 'import'] === $actions[0]->getOptions()['entries'],
        ));
        $views = new ViewCollection();
        $views->add($builder);

        $this->admin([])->configureViews($views);
    }

    public function testRunsAfterTheAdminsThatBuildTheLists(): void
    {
        $this->assertLessThan(0, ContentTransferAdmin::getPriority());
    }

    public function testTheOldActionNamesStayAvailable(): void
    {
        $this->assertSame('sulu_content_transfer.export', ContentTransferAdmin::EXPORT_TOOLBAR_ACTION);
        $this->assertSame('sulu_content_transfer.import', ContentTransferAdmin::IMPORT_TOOLBAR_ACTION);
    }

    /**
     * @param list<string> $denied "<context>:<permission>" that the user does not have
     */
    private function admin(array $denied, string $toolbar = 'dropdown', bool $mergeCsvExport = true): ContentTransferAdmin
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

        return new ContentTransferAdmin($checker, new ArticleSecurityContexts($provider), $toolbar, $mergeCsvExport);
    }

    /**
     * Lists as Sulu's article admin builds them: add, delete and the CSV export, then the dropdown of another bundle.
     *
     * @param list<string>|null $csvFor lists that get the CSV export, null: every article list if $csv is set
     */
    private function views(bool $csv = true, bool $others = true, ?array $csvFor = null): ViewCollection
    {
        $factory = new ViewBuilderFactory();
        $views = new ViewCollection();
        foreach (['sulu_article.article.list_blog', 'sulu_article.article.list_faq', 'sulu_snippet.list'] as $name) {
            $builder = $factory->createListViewBuilder($name, '/'.$name)->setResourceKey('articles')->setListKey('articles')->addListAdapters(['table']);

            if (str_starts_with($name, 'sulu_article.')) {
                $actions = [];
                if ($others) {
                    $actions[] = new ToolbarAction('sulu_admin.add');
                    $actions[] = new ToolbarAction('sulu_admin.delete');
                }
                if (null !== $csvFor ? \in_array($name, $csvFor, true) : $csv) {
                    $actions[] = new ToolbarAction('sulu_admin.export');
                }
                if ($others) {
                    $actions[] = new ToolbarAction('sulu_bulk_actions.actions');
                }
                if ([] !== $actions) {
                    $builder->addToolbarActions($actions);
                }
            }

            $views->add($builder);
        }

        return $views;
    }

    /**
     * @return list<ToolbarAction>
     */
    private function actions(ViewCollection $views, string $name): array
    {
        return array_values($views->get($name)->getView()->getOption('toolbarActions') ?? []);
    }

    /**
     * @return list<string>
     */
    private function actionTypes(ViewCollection $views, string $name): array
    {
        return array_map(static fn (ToolbarAction $action): string => $action->getType(), $this->actions($views, $name));
    }

    /**
     * The entries of every dropdown of a list, by the name of its action.
     *
     * @return array<string, list<string>>
     */
    private function entries(ViewCollection $views, string $name): array
    {
        $entries = [];
        foreach ($this->actions($views, $name) as $action) {
            if (isset($action->getOptions()['entries'])) {
                $entries[$action->getType()] = $action->getOptions()['entries'];
            }
        }

        return $entries;
    }
}
