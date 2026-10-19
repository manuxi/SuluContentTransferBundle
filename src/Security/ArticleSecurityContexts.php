<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Security;

use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;

/**
 * Articles have one security context per article group (Blog, FAQ, ...), the same ones the role form shows. A system
 * with one group, and the default group, use the base context of the articles (the rule of the ArticleAdmin).
 */
class ArticleSecurityContexts
{
    public function __construct(private readonly GroupProviderInterface $groupProvider)
    {
    }

    /**
     * The context of the article list with this view name, null if it is not a list of articles.
     */
    public function forListView(string $viewName): ?string
    {
        $prefix = ArticleAdmin::LIST_VIEW.'_';
        if (!str_starts_with($viewName, $prefix)) {
            return null;
        }

        return $this->contextOfGroup($this->groups(), substr($viewName, \strlen($prefix)));
    }

    /**
     * Every context an article can be in: the base context and one per article group.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $groups = $this->groups();
        $contexts = [ArticleAdmin::SECURITY_CONTEXT];
        foreach ($groups as $group) {
            $contexts[] = $this->contextOfGroup($groups, $group->identifier);
        }

        return array_values(array_unique($contexts));
    }

    /**
     * The context of an article with this template; the base context if no group contains the template.
     */
    public function forTemplateKey(?string $templateKey): string
    {
        $groups = $this->groups();
        if (null !== $templateKey) {
            foreach ($groups as $group) {
                if (\in_array($templateKey, $group->templates, true)) {
                    return $this->contextOfGroup($groups, $group->identifier);
                }
            }
        }

        return ArticleAdmin::SECURITY_CONTEXT;
    }

    /**
     * @return array<string, FormGroup>
     */
    private function groups(): array
    {
        return $this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE);
    }

    /**
     * @param array<string, FormGroup> $groups
     */
    private function contextOfGroup(array $groups, string $identifier): string
    {
        if (1 === \count($groups) || GroupProviderInterface::DEFAULT_GROUP === $identifier) {
            return ArticleAdmin::SECURITY_CONTEXT;
        }

        return ArticleAdmin::getArticleSecurityContext($identifier);
    }
}
