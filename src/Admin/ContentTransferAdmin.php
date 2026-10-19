<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Admin;

use Manuxi\SuluContentTransferBundle\Security\ArticleSecurityContexts;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

/**
 * Adds the toolbar action "Export" to the article lists of every article group. The action is only offered to users
 * who may view articles in the security context of that group; the server checks every article again.
 */
class ContentTransferAdmin extends Admin
{
    public const EXPORT_TOOLBAR_ACTION = 'sulu_content_transfer.export';

    public function __construct(
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly ArticleSecurityContexts $contexts,
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
            if (null === $context || !$this->securityChecker->hasPermission($context, PermissionTypes::VIEW)) {
                continue;
            }

            $viewBuilder->addToolbarActions([new ToolbarAction(self::EXPORT_TOOLBAR_ACTION)]);
        }
    }
}
