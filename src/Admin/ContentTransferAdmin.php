<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Admin;

use Manuxi\SuluContentTransferBundle\Security\ArticleSecurityContexts;
use Psr\Log\LoggerInterface;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

/**
 * Adds the package export and import to the article lists of every article group. By default (toolbar "dropdown") one
 * dropdown "Export/Import" takes the place of the CSV export of Sulu, with the entries "Export package" and "Import
 * package". With the toolbar "buttons" the list gets the two single buttons of version 1.0 instead.
 *
 * The CSV export of Sulu does not work for articles in Sulu 3.0 (the article list always answers with JSON). It is
 * removed from the article lists unless the option "article_csv_export" is set; then the dropdown has the entry
 * "Export list as CSV" as well (or Sulu's button stays, as before). Other lists are not touched.
 *
 * Export is offered to users who may view articles in the security context of that group, import to users who may add
 * or edit them; the server checks every article again. An entry the user may not use is not offered; without any entry
 * the list stays as it is.
 */
class ContentTransferAdmin extends Admin
{
    public const TRANSFER_TOOLBAR_ACTION = 'sulu_content_transfer.transfer';
    public const EXPORT_TOOLBAR_ACTION = 'sulu_content_transfer.export';
    public const IMPORT_TOOLBAR_ACTION = 'sulu_content_transfer.import';

    public const TOOLBAR_DROPDOWN = 'dropdown';
    public const TOOLBAR_BUTTONS = 'buttons';

    public const ENTRY_CSV = 'csv';
    public const ENTRY_EXPORT = 'export';
    public const ENTRY_IMPORT = 'import';

    private const SULU_EXPORT_TOOLBAR_ACTION = 'sulu_admin.export';

    public function __construct(
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly ArticleSecurityContexts $contexts,
        private readonly string $toolbar = self::TOOLBAR_DROPDOWN,
        private readonly bool $mergeCsvExport = true,
        private readonly bool $articleCsvExport = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Runs after the admins that build the lists.
     */
    public static function getPriority(): int
    {
        return -1000;
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        foreach ($viewCollection->all() as $name => $viewBuilder) {
            if (!$viewBuilder instanceof ListViewBuilderInterface) {
                continue;
            }

            $context = $this->contexts->forListView($name);
            if (null === $context) {
                continue;
            }

            $canExport = $this->securityChecker->hasPermission($context, PermissionTypes::VIEW);
            $canImport = $this->securityChecker->hasPermission($context, PermissionTypes::ADD)
                || $this->securityChecker->hasPermission($context, PermissionTypes::EDIT);

            $this->configureList($viewBuilder, $canExport, $canImport);
        }
    }

    /**
     * Sets up one article list. The CSV export of Sulu does not work for articles (their list always answers with JSON),
     * so unless the option "article_csv_export" is set the button is taken out. The dropdown (or the two buttons) offer
     * what the user may do; the CSV entry of the dropdown only exists with the option set.
     */
    private function configureList(ListViewBuilderInterface $viewBuilder, bool $canExport, bool $canImport): void
    {
        $dropdown = self::TOOLBAR_DROPDOWN === $this->toolbar;

        $entries = [];
        if ($canExport) {
            $entries[] = self::ENTRY_EXPORT;
        }
        if ($canImport) {
            $entries[] = self::ENTRY_IMPORT;
        }

        // the dropdown takes the place of the CSV button of Sulu, and the button is removed if it must not stay
        $takesPlace = $dropdown && ($this->mergeCsvExport || !$this->articleCsvExport);
        $view = null;
        $toolbarActions = [];
        $csvIndex = null;
        if (!$this->articleCsvExport || $takesPlace) {
            $view = $this->viewOf($viewBuilder);
            if (null === $view) {
                $this->logger?->notice('The toolbar of the article list "{list}" could not be changed, the CSV export button of Sulu stays.', ['list' => $viewBuilder->getName()]);
            } else {
                $toolbarActions = $view->getOption('toolbarActions');
                $toolbarActions = \is_array($toolbarActions) ? array_values($toolbarActions) : [];
                $csvIndex = $this->indexOfCsvExport($toolbarActions);
            }
        }

        if ($dropdown) {
            if (null !== $csvIndex && $this->articleCsvExport && $this->mergeCsvExport && [] !== $entries) {
                array_unshift($entries, self::ENTRY_CSV);
            }
            $added = [] === $entries ? [] : [new ToolbarAction(self::TRANSFER_TOOLBAR_ACTION, ['entries' => $entries])];
        } else {
            $added = [];
            if ($canExport) {
                $added[] = new ToolbarAction(self::EXPORT_TOOLBAR_ACTION);
            }
            if ($canImport) {
                $added[] = new ToolbarAction(self::IMPORT_TOOLBAR_ACTION);
            }
        }

        if (null !== $view && null !== $csvIndex && (!$this->articleCsvExport || [] !== $added)) {
            if ($takesPlace && [] !== $added) {
                array_splice($toolbarActions, $csvIndex, 1, $added);
                $added = [];
            } else {
                array_splice($toolbarActions, $csvIndex, 1);
            }
            $view->setOption('toolbarActions', $toolbarActions);
        }

        if ([] !== $added) {
            $viewBuilder->addToolbarActions($added);
        }
    }

    /**
     * The view a list view builder works on. The builder hands out a clone in getView() and has no method to remove or
     * replace a toolbar action, so the only way to swap an action is to reach the view itself. Null if the builder
     * keeps its view somewhere else; the dropdown is then appended and the CSV export stays a button.
     */
    private function viewOf(ListViewBuilderInterface $viewBuilder): ?View
    {
        for ($class = new \ReflectionClass($viewBuilder); false !== $class; $class = $class->getParentClass()) {
            if (!$class->hasProperty('view')) {
                continue;
            }

            $property = $class->getProperty('view');
            if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $view = $property->getValue($viewBuilder);

            return $view instanceof View ? $view : null;
        }

        return null;
    }

    /**
     * @param list<mixed> $toolbarActions
     */
    private function indexOfCsvExport(array $toolbarActions): ?int
    {
        foreach ($toolbarActions as $index => $action) {
            if ($action instanceof ToolbarAction && self::SULU_EXPORT_TOOLBAR_ACTION === $action->getType()) {
                return $index;
            }
        }

        return null;
    }
}
