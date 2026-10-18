<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Bundle\ContactBundle\Entity\ContactInterface;
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Route\Domain\Model\Route;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;

/**
 * Reads the lookup data from the database through Doctrine.
 */
class DoctrineLookupSource implements LookupSource
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function pages(array $uuids): array
    {
        $titles = $this->titles(PageDimensionContentInterface::class, 'page', $uuids);
        $urls = $this->urls('pages', $uuids);

        /** @var list<array{uuid: string, webspace: string|null}> $webspaces */
        $webspaces = $this->entityManager->createQueryBuilder()
            ->select('p.uuid AS uuid', 'p.webspaceKey AS webspace')
            ->from(PageInterface::class, 'p')
            ->where('p.uuid IN (:uuids)')
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getArrayResult();

        $rows = [];
        foreach ($webspaces as $row) {
            $uuid = $row['uuid'];
            $rows[] = [
                'uuid' => $uuid,
                'webspace' => $row['webspace'],
                'urls' => $urls[$uuid] ?? [],
                'titles' => $titles[$uuid]['titles'] ?? [],
            ];
        }

        return $rows;
    }

    public function snippets(array $uuids): array
    {
        $titles = $this->titles(SnippetDimensionContentInterface::class, 'snippet', $uuids);

        $rows = [];
        foreach ($titles as $uuid => $info) {
            $rows[] = ['uuid' => $uuid, 'templateKey' => $info['templateKey'], 'titles' => $info['titles']];
        }

        return $rows;
    }

    public function articles(array $uuids): array
    {
        $titles = $this->titles(ArticleDimensionContentInterface::class, 'article', $uuids);
        $urls = $this->urls('articles', $uuids);

        $rows = [];
        foreach ($titles as $uuid => $info) {
            $rows[] = ['uuid' => $uuid, 'templateKey' => $info['templateKey'], 'titles' => $info['titles'], 'urls' => $urls[$uuid] ?? []];
        }

        return $rows;
    }

    public function categories(array $ids): array
    {
        /** @var list<CategoryInterface> $categories */
        $categories = $this->entityManager->getRepository(CategoryInterface::class)->findBy(['id' => $ids]);

        $rows = [];
        foreach ($categories as $category) {
            $path = [];
            for ($parent = $category->getParent(); null !== $parent; $parent = $parent->getParent()) {
                array_unshift($path, ['key' => $parent->getKey(), 'names' => self::names($parent)]);
            }

            $rows[] = ['id' => (int) $category->getId(), 'key' => $category->getKey(), 'names' => self::names($category), 'path' => $path];
        }

        return $rows;
    }

    public function tags(array $ids, array $names): array
    {
        $repository = $this->entityManager->getRepository(TagInterface::class);

        /** @var array<int, TagInterface> $tags */
        $tags = [];
        if ([] !== $ids) {
            foreach ($repository->findBy(['id' => $ids]) as $tag) {
                $tags[$tag->getId()] = $tag;
            }
        }
        if ([] !== $names) {
            foreach ($repository->findBy(['name' => $names]) as $tag) {
                $tags[$tag->getId()] = $tag;
            }
        }

        $rows = [];
        foreach ($tags as $tag) {
            $rows[] = ['id' => $tag->getId(), 'name' => $tag->getName()];
        }

        return $rows;
    }

    public function contacts(array $ids): array
    {
        /** @var list<ContactInterface> $contacts */
        $contacts = $this->entityManager->getRepository(ContactInterface::class)->findBy(['id' => $ids]);

        $rows = [];
        foreach ($contacts as $contact) {
            $rows[] = [
                'id' => (int) $contact->getId(),
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'email' => $contact->getMainEmail(),
            ];
        }

        return $rows;
    }

    /**
     * Title and template of the current draft of every language.
     *
     * @param class-string $dimensionContentClass
     * @param list<string> $uuids
     *
     * @return array<string, array{templateKey: string|null, titles: array<string, string>}>
     */
    private function titles(string $dimensionContentClass, string $association, array $uuids): array
    {
        /** @var list<array{uuid: string, locale: string, title: string|null, templateKey: string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.uuid AS uuid', 'dc.locale AS locale', 'dc.title AS title', 'dc.templateKey AS templateKey')
            ->from($dimensionContentClass, 'dc')
            ->join('dc.' . $association, 'r')
            ->where('r.uuid IN (:uuids)')
            ->andWhere('dc.stage = :stage')
            ->andWhere('dc.version = :version')
            ->andWhere('dc.locale IS NOT NULL')
            ->setParameter('uuids', $uuids)
            ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
            ->setParameter('version', DimensionContentInterface::CURRENT_VERSION)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['uuid']]['templateKey'] = $row['templateKey'];
            $result[$row['uuid']]['titles'][$row['locale']] = (string) $row['title'];
        }

        return $result;
    }

    /**
     * Current addresses (not the redirects of old ones) per language.
     *
     * @param list<string> $uuids
     *
     * @return array<string, array<string, string>>
     */
    private function urls(string $resourceKey, array $uuids): array
    {
        /** @var list<array{resourceId: string, locale: string, slug: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.resourceId AS resourceId', 'r.locale AS locale', 'r.slug AS slug')
            ->from(Route::class, 'r')
            ->where('r.resourceKey = :resourceKey')
            ->andWhere('r.resourceId IN (:uuids)')
            ->setParameter('resourceKey', $resourceKey)
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['resourceId']][$row['locale']] = $row['slug'];
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function names(CategoryInterface $category): array
    {
        $names = [];
        foreach ($category->getTranslations() as $translation) {
            $names[$translation->getLocale()] = $translation->getTranslation();
        }

        return $names;
    }
}
