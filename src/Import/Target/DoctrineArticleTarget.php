<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Route\Domain\Model\Route;

/**
 * Articles, templates and addresses of this system through Doctrine and the form metadata of Sulu.
 */
class DoctrineArticleTarget implements ArticleTarget
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MetadataProviderRegistry $metadataProviderRegistry,
    ) {
    }

    public function templateKeys(): array
    {
        $typed = $this->metadataProviderRegistry->getMetadataProvider('form')->getMetadata('article', 'en', []);
        if (!$typed instanceof TypedFormMetadata) {
            return [];
        }

        return array_map('strval', array_keys($typed->getForms()));
    }

    public function findArticle(string $uuid): ?TargetArticle
    {
        $article = $this->entityManager->getRepository(ArticleInterface::class)->findOneBy(['uuid' => $uuid]);
        if (!$article instanceof ArticleInterface) {
            return null;
        }

        /** @var list<array{locale: string, stage: string, changed: \DateTimeInterface, username: string|null, firstName: string|null, lastName: string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('dc.locale AS locale', 'dc.stage AS stage', 'dc.changed AS changed', 'u.username AS username', 'c.firstName AS firstName', 'c.lastName AS lastName')
            ->from(ArticleDimensionContentInterface::class, 'dc')
            ->join('dc.article', 'a')
            ->leftJoin('dc.changer', 'u')
            ->leftJoin('u.contact', 'c')
            ->where('a.uuid = :uuid')
            ->andWhere('dc.version = :version')
            ->andWhere('dc.locale IS NOT NULL')
            ->setParameter('uuid', $uuid)
            ->setParameter('version', DimensionContentInterface::CURRENT_VERSION)
            ->getQuery()
            ->getArrayResult();

        $dimensions = [];
        foreach ($rows as $row) {
            if (!\in_array($row['stage'], ['live', 'draft'], true)) {
                continue;
            }
            $name = trim(($row['firstName'] ?? '').' '.($row['lastName'] ?? ''));
            $dimensions[] = new TargetDimension(
                $row['locale'],
                $row['stage'],
                \DateTimeImmutable::createFromInterface($row['changed']),
                '' !== $name ? $name : $row['username'],
            );
        }

        return new TargetArticle($article->getUuid(), $dimensions);
    }

    public function routeOwner(string $locale, string $slug, ?string $excludeArticleUuid): ?string
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('r.resourceKey AS resourceKey', 'r.resourceId AS resourceId')
            ->from(Route::class, 'r')
            ->where('r.locale = :locale')
            ->andWhere('r.slug = :slug')
            ->setParameter('locale', $locale)
            ->setParameter('slug', $slug)
            ->setMaxResults(1);

        if (null !== $excludeArticleUuid) {
            $builder
                ->andWhere('NOT (r.resourceKey = :articles AND r.resourceId = :excluded)')
                ->setParameter('articles', 'articles')
                ->setParameter('excluded', $excludeArticleUuid);
        }

        /** @var list<array{resourceKey: string, resourceId: string}> $rows */
        $rows = $builder->getQuery()->getArrayResult();

        return [] === $rows ? null : $rows[0]['resourceKey'].':'.$rows[0]['resourceId'];
    }
}
