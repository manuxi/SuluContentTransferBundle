<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;

/**
 * Creates categories and tags through Sulu's managers (like the admin does).
 */
class SuluTaxonomyWriter implements TaxonomyWriter
{
    public function __construct(
        private readonly CategoryManagerInterface $categories,
        private readonly TagManagerInterface $tags,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportUserContext $user,
    ) {
    }

    public function createCategory(?string $key, array $names, ?int $parentId): int
    {
        $locales = array_keys($names);
        $first = (string) ($locales[0] ?? 'en');
        $name = $names[$first] ?? ($key ?? 'Category');

        $data = ['name' => '' !== $name ? $name : ($key ?? 'Category')];
        if (null !== $key && '' !== $key) {
            $data['key'] = $key;
        }
        if (null !== $parentId) {
            $data['parent'] = $parentId;
        }

        $category = $this->categories->save($data, $this->user->userId(), $first);
        $id = (int) $category->getId();

        // the names in the other languages are added to the same category
        foreach (\array_slice($names, 1, null, true) as $locale => $translated) {
            if ('' === $translated) {
                continue;
            }
            $this->categories->save(['id' => $id, 'name' => $translated], $this->user->userId(), (string) $locale, true);
        }
        $this->entityManager->flush();

        return $id;
    }

    public function createTag(string $name): int
    {
        $tag = $this->tags->findOrCreateByName($name);
        $this->entityManager->flush();

        return (int) $tag->getId();
    }
}
