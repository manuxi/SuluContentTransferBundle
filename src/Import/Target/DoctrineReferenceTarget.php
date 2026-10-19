<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Bundle\ContactBundle\Entity\AccountInterface;
use Sulu\Bundle\ContactBundle\Entity\ContactInterface;
use Sulu\Bundle\MediaBundle\Entity\CollectionInterface;
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Route\Domain\Model\Route;
use Sulu\Snippet\Domain\Model\SnippetInterface;

/**
 * Pages, snippets, categories, tags, contacts and the like of this system through Doctrine.
 */
class DoctrineReferenceTarget implements ReferenceTarget
{
    private const TARGET_GROUP_CLASS = 'Sulu\Bundle\AudienceTargetingBundle\Entity\TargetGroup';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function page(string $uuid): ?TargetPage
    {
        $page = $this->entityManager->getRepository(PageInterface::class)->findOneBy(['uuid' => $uuid]);
        if (!$page instanceof PageInterface) {
            return null;
        }

        /** @var list<array{locale: string, slug: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.locale AS locale', 'r.slug AS slug')
            ->from(Route::class, 'r')
            ->where('r.resourceKey = :resourceKey')
            ->andWhere('r.resourceId = :uuid')
            ->setParameter('resourceKey', 'pages')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getArrayResult();

        $urls = [];
        foreach ($rows as $row) {
            $urls[$row['locale']] = $row['slug'];
        }

        return new TargetPage($page->getUuid(), $page->getWebspaceKey(), $urls);
    }

    public function pageByUrl(string $locale, string $url): ?TargetPage
    {
        /** @var list<array{resourceId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.resourceId AS resourceId')
            ->from(Route::class, 'r')
            ->where('r.resourceKey = :resourceKey')
            ->andWhere('r.locale = :locale')
            ->andWhere('r.slug = :slug')
            ->setParameter('resourceKey', 'pages')
            ->setParameter('locale', $locale)
            ->setParameter('slug', $url)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return [] === $rows ? null : $this->page((string) $rows[0]['resourceId']);
    }

    public function exists(string $resourceKey, int|string $id): bool
    {
        $repositoryFor = fn (string $class) => $this->entityManager->getRepository($class);

        return match ($resourceKey) {
            'articles' => null !== $repositoryFor(ArticleInterface::class)->findOneBy(['uuid' => (string) $id]),
            'snippets' => null !== $repositoryFor(SnippetInterface::class)->findOneBy(['uuid' => (string) $id]),
            'accounts' => null !== $repositoryFor(AccountInterface::class)->find((int) $id),
            'collections' => null !== $repositoryFor(CollectionInterface::class)->find((int) $id),
            'target_groups' => $this->isMapped(self::TARGET_GROUP_CLASS) && null !== $repositoryFor(self::TARGET_GROUP_CLASS)->find((int) $id),
            default => false,
        };
    }

    /**
     * Whether the class is an entity of this system (the audience targeting bundle may be installed but not enabled).
     */
    private function isMapped(string $class): bool
    {
        return class_exists($class) && !$this->entityManager->getMetadataFactory()->isTransient($class);
    }

    public function categoryByKey(string $key): ?int
    {
        $category = $this->entityManager->getRepository(CategoryInterface::class)->findOneBy(['key' => $key]);

        return $category instanceof CategoryInterface ? (int) $category->getId() : null;
    }

    public function categoryByNames(array $names, ?int $parentId): ?int
    {
        $lowered = array_values(array_unique(array_map(static fn (string $name): string => mb_strtolower($name), array_filter($names, static fn (string $name): bool => '' !== $name))));
        if ([] === $lowered) {
            return null;
        }

        $builder = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT c.id AS id')
            ->from(CategoryInterface::class, 'c')
            ->join('c.translations', 't')
            ->where('LOWER(t.translation) IN (:names)')
            ->setParameter('names', $lowered)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(1);

        if (null === $parentId) {
            $builder->andWhere('c.parent IS NULL');
        } else {
            $builder->andWhere('IDENTITY(c.parent) = :parent')->setParameter('parent', $parentId);
        }

        /** @var list<array{id: int|string}> $rows */
        $rows = $builder->getQuery()->getArrayResult();

        return [] === $rows ? null : (int) $rows[0]['id'];
    }

    public function tagByName(string $name): ?int
    {
        $tag = $this->entityManager->getRepository(TagInterface::class)->findOneBy(['name' => $name]);

        return $tag instanceof TagInterface ? (int) $tag->getId() : null;
    }

    public function contactByEmail(string $email): ?int
    {
        $contact = $this->entityManager->getRepository(ContactInterface::class)->findOneBy(['mainEmail' => $email], ['id' => 'ASC']);

        return $contact instanceof ContactInterface ? (int) $contact->getId() : null;
    }

    public function contactsByName(?string $firstName, ?string $lastName): array
    {
        if (null === $firstName && null === $lastName) {
            return [];
        }

        $criteria = [];
        if (null !== $firstName) {
            $criteria['firstName'] = $firstName;
        }
        if (null !== $lastName) {
            $criteria['lastName'] = $lastName;
        }

        $ids = [];
        foreach ($this->entityManager->getRepository(ContactInterface::class)->findBy($criteria, ['id' => 'ASC']) as $contact) {
            $ids[] = (int) $contact->getId();
        }

        return $ids;
    }
}
