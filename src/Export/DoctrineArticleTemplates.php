<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

class DoctrineArticleTemplates implements ArticleTemplates
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function templateKeys(array $uuids): array
    {
        if ([] === $uuids) {
            return [];
        }

        // A scalar query on purpose: loading the articles as objects would fill the identity map with drafts only.
        /** @var list<array{uuid: string, locale: string, templateKey: ?string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('article.uuid AS uuid', 'dimensionContent.locale AS locale', 'dimensionContent.templateKey AS templateKey')
            ->from(ArticleDimensionContentInterface::class, 'dimensionContent')
            ->innerJoin('dimensionContent.article', 'article')
            ->where('article.uuid IN (:uuids)')
            ->andWhere('dimensionContent.stage = :stage')
            ->andWhere('dimensionContent.version = :version')
            ->andWhere('dimensionContent.locale IS NOT NULL')
            ->setParameter('uuids', array_values(array_unique($uuids)))
            ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
            ->setParameter('version', DimensionContentInterface::CURRENT_VERSION)
            ->getQuery()
            ->getArrayResult();

        $keys = [];
        foreach ($rows as $row) {
            $keys[strtolower($row['uuid'])][$row['locale']] = (string) $row['templateKey'];
        }

        return $keys;
    }
}
