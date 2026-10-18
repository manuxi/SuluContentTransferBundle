<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Reference;

use Sulu\Content\Application\ContentResolver\ContentViewResolver\ContentViewResolverInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

/**
 * Collects the references of a resolved dimension content the way Sulu does for its references table
 * (see Sulu's ArticleReferenceRefresher): resolve the content views, then walk them recursively.
 */
class SuluReferenceSource
{
    public function __construct(private readonly ContentViewResolverInterface $contentViewResolver)
    {
    }

    /**
     * @template T of ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     *
     * @return list<array{resolver: string, path: string, resourceKey: string, id: int|string}>
     */
    public function collect(DimensionContentInterface $dimensionContent): array
    {
        $references = [];

        foreach ($this->contentViewResolver->getContentViews($dimensionContent) as $key => $contentView) {
            $basePath = 'template' !== $key ? (string) $key : '';

            foreach ($contentView->getAllReferencesRecursively($basePath) as $reference) {
                $references[] = [
                    'resolver' => (string) $key,
                    'path' => $reference->getPath(),
                    'resourceKey' => $reference->getResourceKey(),
                    'id' => $reference->getResourceId(),
                ];
            }
        }

        return $references;
    }
}
