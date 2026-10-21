<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Admin;

use Manuxi\SuluContentTransferBundle\Security\ArticleSecurityContexts;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

/**
 * Adds the package export and import to the article lists of every article group. By default (toolbar "dropdown") one
 * dropdown "Export/Import" takes the place of the CSV export of Sulu, with the entries "Export list as CSV", "Export
 * package" and "Import package". With the toolbar "buttons" the list gets the two single buttons of version 1.0 instead.
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

            if (self::TOOLBAR_BUTTONS === $this->toolbar) {
                $actions = [];
                if ($canExport) {
                    $actions[] = new ToolbarAction(self::EXPORT_TOOLBAR_ACTION);
                }
                if ($canImport) {
                    $actions[] = new ToolbarAction(self::IMPORT_TOOLBAR_ACTION);
                }
                if ([] !== $actions) {
                    $viewBuilder->addToolbarActions($actions);
                }

                continue;
            }

            $entries = [];
            if ($canExport) {
                $entries[] = self::ENTRY_EXPORT;
            }
            if ($canImport) {
                $entries[] = self::ENTRY_IMPORT;
            }
            if ([] === $entries) {
                continue;
            }

            $this->addDropdown($viewBuilder, $entries);
        }
    }

    /**
     * Puts the dropdown in the place of the CSV export of Sulu (and adds that export as its first entry), or at the
     * end of the toolbar if the list has none or the CSV export stays a button of its own.
     *
     * @param list<string> $entries
     */
    private function addDropdown(ListViewBuilderInterface $viewBuilder, array $entries): void
    {
        $view = $this->mergeCsvExport ? $this->viewOf($viewBuilder) : null;
        $toolbarActions = $view?->getOption('toolbarActions');
        $toolbarActions = \is_array($toolbarActions) ? array_values($toolbarActions) : [];
        $csvIndex = null !== $view ? $this->indexOfCsvExport($toolbarActions) : null;

        if (null === $csvIndex || null === $view) {
            $viewBuilder->addToolbarActions([new ToolbarAction(self::TRANSFER_TOOLBAR_ACTION, ['entries' => $entries])]);

            return;
        }

        array_unshift($entries, self::ENTRY_CSV);
        $toolbarActions[$csvIndex] = new ToolbarAction(self::TRANSFER_TOOLBAR_ACTION, ['entries' => $entries]);
        $view->setOption('toolbarActions', $toolbarActions);
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
