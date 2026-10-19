<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;

/**
 * "/admin/#/<language>/<group>/<uuid>": the edit view of the article in the group (article list) its template belongs to.
 */
class SuluAdminLinks implements AdminLinks
{
    public function __construct(private readonly GroupProviderInterface $groupProvider)
    {
    }

    public function article(string $uuid, string $locale, ?string $templateKey): ?string
    {
        $groups = $this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE);
        $identifier = null;
        foreach ($groups as $group) {
            $identifier ??= $group->identifier;
            if (null !== $templateKey && \in_array($templateKey, $group->templates, true)) {
                $identifier = $group->identifier;

                break;
            }
        }
        if (null === $identifier) {
            return null;
        }

        return '/admin/#/'.$locale.'/'.$identifier.'/'.$uuid;
    }
}
