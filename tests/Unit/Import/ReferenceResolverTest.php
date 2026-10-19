<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;
use Manuxi\SuluContentTransferBundle\Format\ReferenceLookup;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Import\ReferenceResolver;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetPage;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMappings;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeReferenceTarget;
use PHPUnit\Framework\TestCase;

class ReferenceResolverTest extends TestCase
{
    private const SOURCE = '0123456789abcdef';
    private const PAGE = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const OTHER_PAGE = 'aaaaaaaa-0000-4000-8000-000000000002';

    private FakeReferenceTarget $target;
    private FakeMappings $mappings;

    protected function setUp(): void
    {
        $this->target = new FakeReferenceTarget();
        $this->mappings = new FakeMappings();
    }

    /**
     * @param list<string> $packageArticles
     */
    private function resolver(?ReferenceLookup $lookup = null, array $packageArticles = []): ReferenceResolver
    {
        return new ReferenceResolver($this->target, $this->mappings, self::SOURCE, $lookup ?? new ReferenceLookup(), array_fill_keys($packageArticles, true));
    }

    private function pagesLookup(): ReferenceLookup
    {
        return new ReferenceLookup(pages: [['uuid' => self::PAGE, 'webspace' => 'w', 'urls' => ['de' => '/leistungen', 'en' => '/services'], 'titles' => ['de' => 'Leistungen']]]);
    }

    public function testPageWithTheSameUuidIsMapped(): void
    {
        $this->target->pages[self::PAGE] = new TargetPage(self::PAGE, 'w', ['de' => '/anders']);

        $resolution = $this->resolver($this->pagesLookup())->resolve('pages', self::PAGE);

        $this->assertSame(PlanResolution::MAPPED, $resolution->status);
        $this->assertSame(self::PAGE, $resolution->targetId);
    }

    public function testPageIsFoundByItsAddressWhenTheUuidIsUnknown(): void
    {
        $this->target->pages[self::OTHER_PAGE] = new TargetPage(self::OTHER_PAGE, 'w', ['en' => '/services']);

        $resolution = $this->resolver($this->pagesLookup())->resolve('pages', self::PAGE);

        $this->assertSame(PlanResolution::MAPPED, $resolution->status);
        $this->assertSame(self::OTHER_PAGE, $resolution->targetId);
    }

    public function testPageThatCannotBeFoundIsRemoved(): void
    {
        $resolution = $this->resolver($this->pagesLookup())->resolve('pages', self::PAGE);

        $this->assertSame(PlanResolution::REMOVED, $resolution->status);
    }

    public function testArticleOfThePackageIsMarkedAsPackage(): void
    {
        $this->target->existing['articles|'.self::PAGE] = true;

        $this->assertSame(PlanResolution::PACKAGE, $this->resolver(null, [self::PAGE])->resolve('articles', self::PAGE)->status);
    }

    public function testArticleOutsideThePackageIsFoundThroughTheMappingThenByUuid(): void
    {
        $this->mappings->add(new TransferMapping(self::SOURCE, 'articles', self::PAGE, self::OTHER_PAGE, new \DateTimeImmutable()));
        $this->target->existing['articles|'.self::OTHER_PAGE] = true;
        $this->target->existing['articles|'.self::PAGE] = true;

        $this->assertSame(self::OTHER_PAGE, $this->resolver()->resolve('articles', self::PAGE)->targetId, 'mapping first');

        $this->mappings = new FakeMappings();
        $this->assertSame(self::PAGE, $this->resolver()->resolve('articles', self::PAGE)->targetId, 'then the same UUID');

        $this->target->existing = [];
        $this->assertSame(PlanResolution::REMOVED, $this->resolver()->resolve('articles', self::PAGE)->status);
    }

    public function testSnippetIsFoundByUuidOrRemoved(): void
    {
        $this->target->existing['snippets|'.self::PAGE] = true;

        $this->assertSame(PlanResolution::MAPPED, $this->resolver()->resolve('snippets', self::PAGE)->status);
        $this->assertSame(PlanResolution::REMOVED, $this->resolver()->resolve('snippets', self::OTHER_PAGE)->status);
    }

    private function categoryLookup(): ReferenceLookup
    {
        return new ReferenceLookup(categories: [
            ['id' => 7, 'key' => 'beginners', 'names' => ['de' => 'Einsteiger', 'en' => 'Beginners'], 'path' => [
                ['key' => 'tutorials', 'names' => ['de' => 'Tutorials', 'en' => 'Tutorials']],
                ['key' => null, 'names' => ['de' => 'Sulu', 'en' => 'Sulu']],
            ]],
            ['id' => 8, 'key' => 'experts', 'names' => ['de' => 'Experten', 'en' => 'Experts'], 'path' => [
                ['key' => 'tutorials', 'names' => ['de' => 'Tutorials', 'en' => 'Tutorials']],
                ['key' => null, 'names' => ['de' => 'Sulu', 'en' => 'Sulu']],
            ]],
        ]);
    }

    public function testCategoryIsFoundByItsKey(): void
    {
        $this->target->categoryKeys['beginners'] = 51;

        $resolver = $this->resolver($this->categoryLookup());
        $resolution = $resolver->resolve('categories', 7);

        $this->assertSame(PlanResolution::MAPPED, $resolution->status);
        $this->assertSame(51, $resolution->targetId);
        $this->assertSame([], $resolver->categories());
    }

    public function testCategoryWithoutAKeyMatchIsFoundByNameBelowTheParentFoundBefore(): void
    {
        $this->target->categoryKeys['tutorials'] = 50;
        $this->target->categories = [
            ['id' => 60, 'parent' => 50, 'names' => ['sulu']],
            ['id' => 61, 'parent' => 60, 'names' => ['EINSTEIGER']],
            ['id' => 62, 'parent' => null, 'names' => ['Einsteiger']],
        ];

        $resolution = $this->resolver($this->categoryLookup())->resolve('categories', 7);

        $this->assertSame(PlanResolution::MAPPED, $resolution->status);
        $this->assertSame(61, $resolution->targetId, 'compared without regard to case, only below the matching parent');
    }

    public function testMissingCategoryIsPlannedTogetherWithItsMissingParents(): void
    {
        $resolver = $this->resolver($this->categoryLookup());
        $resolution = $resolver->resolve('categories', 7);

        $this->assertSame(PlanResolution::PLANNED, $resolution->status);
        $this->assertSame('key:beginners', $resolution->ref);

        $planned = [];
        foreach ($resolver->categories() as $category) {
            $planned[$category->ref] = $category;
        }
        $this->assertCount(3, $planned);

        $root = $planned['key:tutorials'];
        $this->assertNull($root->parentId);
        $this->assertNull($root->parentRef);
        $this->assertNull($root->sourceId, 'a parent that only appears in a path has no source id');

        $middle = array_values(array_filter($planned, static fn ($c): bool => null === $c->key))[0];
        $this->assertStringStartsWith('path:', $middle->ref);
        $this->assertSame('key:tutorials', $middle->parentRef);

        $leaf = $planned['key:beginners'];
        $this->assertSame($middle->ref, $leaf->parentRef);
        $this->assertSame(7, $leaf->sourceId);
        $this->assertSame(['de' => 'Einsteiger', 'en' => 'Beginners'], $leaf->names);
    }

    public function testMissingCategoryBelowAnExistingParentUsesThatParent(): void
    {
        $this->target->categoryKeys['tutorials'] = 50;
        $this->target->categories = [['id' => 60, 'parent' => 50, 'names' => ['Sulu']]];

        $resolver = $this->resolver($this->categoryLookup());
        $resolver->resolve('categories', 7);

        $planned = $resolver->categories();
        $this->assertCount(1, $planned);
        $this->assertSame('key:beginners', $planned[0]->ref);
        $this->assertSame(60, $planned[0]->parentId);
        $this->assertNull($planned[0]->parentRef);
    }

    public function testSharedParentsAreOnlyPlannedOnce(): void
    {
        $resolver = $this->resolver($this->categoryLookup());
        $first = $resolver->resolve('categories', 7);
        $second = $resolver->resolve('categories', 8);

        $this->assertNotSame($first->ref, $second->ref);
        $refs = array_map(static fn ($c): string => $c->ref, $resolver->categories());
        $this->assertCount(4, $refs, 'tutorials, sulu, beginners, experts');
        $this->assertCount(4, array_unique($refs));
    }

    public function testCategoryWithoutLookupDataIsRemoved(): void
    {
        $this->assertSame(PlanResolution::REMOVED, $this->resolver()->resolve('categories', 99)->status);
    }

    public function testTagsAreFoundByNameOrPlanned(): void
    {
        $this->target->tags['Sulu'] = 5;
        $lookup = new ReferenceLookup(tags: [['id' => 1, 'name' => 'Sulu'], ['id' => 2, 'name' => 'Neu']]);
        $resolver = $this->resolver($lookup);

        $this->assertSame(5, $resolver->resolve('tags', 1)->targetId);
        $this->assertSame(PlanResolution::PLANNED, $resolver->resolve('tags', 2)->status);
        $this->assertSame(PlanResolution::REMOVED, $resolver->resolve('tags', 3)->status);
        $this->assertSame(['Neu'], $resolver->tags());
    }

    public function testSmartContentTagFiltersHoldNamesAndAreNeverRemoved(): void
    {
        $this->target->tags['Sulu'] = 5;
        $resolver = $this->resolver();

        $known = $resolver->resolve('tags', 'Sulu');
        $unknown = $resolver->resolve('tags', 'Übertragungstest');

        $this->assertSame('tag_names', $known->kind);
        $this->assertSame(PlanResolution::MAPPED, $known->status);
        $this->assertSame(PlanResolution::PLANNED, $unknown->status);
        $this->assertSame(['Übertragungstest'], $resolver->tags());
    }

    public function testNumericTagNameAndTagIdAreDifferentThings(): void
    {
        $lookup = new ReferenceLookup(tags: [['id' => 2024, 'name' => 'Jahr']]);
        $resolver = $this->resolver($lookup);

        $byId = $resolver->resolve('tags', 2024);
        $byName = $resolver->resolve('tags', '2024');

        $this->assertSame('tags', $byId->kind);
        $this->assertSame('tag_names', $byName->kind);
        $this->assertCount(2, $resolver->resolutions());
    }

    private function contactLookup(): ReferenceLookup
    {
        return new ReferenceLookup(contacts: [['id' => 3, 'firstName' => 'Erika', 'lastName' => 'Mustermann', 'email' => 'erika@example.org']]);
    }

    public function testContactIsFoundByEmailBeforeByName(): void
    {
        $this->target->contactEmails['erika@example.org'] = 10;
        $this->target->contacts = [['id' => 11, 'first' => 'Erika', 'last' => 'Mustermann']];

        $this->assertSame(10, $this->resolver($this->contactLookup())->resolve('contacts', 3)->targetId);
    }

    public function testContactIsFoundByNameWhenTheEmailIsUnknownAndTheNameUnambiguous(): void
    {
        $this->target->contacts = [['id' => 11, 'first' => 'Erika', 'last' => 'Mustermann']];

        $this->assertSame(11, $this->resolver($this->contactLookup())->resolve('contacts', 3)->targetId);
    }

    public function testAmbiguousOrUnknownContactIsRemoved(): void
    {
        $this->assertSame(PlanResolution::REMOVED, $this->resolver($this->contactLookup())->resolve('contacts', 3)->status);

        $this->target->contacts = [['id' => 11, 'first' => 'Erika', 'last' => 'Mustermann'], ['id' => 12, 'first' => 'Erika', 'last' => 'Mustermann']];
        $this->assertSame(PlanResolution::REMOVED, $this->resolver($this->contactLookup())->resolve('contacts', 3)->status);
    }

    public function testKindsWithoutLookupDataAreKeptWhenTheSameIdExistsAndRemovedOtherwise(): void
    {
        $this->target->existing['accounts|1'] = true;
        $this->target->existing['collections|26'] = true;
        $resolver = $this->resolver();

        $account = $resolver->resolve('accounts', 1);
        $this->assertSame(PlanResolution::UNCHECKED, $account->status);
        $this->assertSame(1, $account->targetId);
        $this->assertSame(PlanResolution::UNCHECKED, $resolver->resolve('collections', 26)->status);
        $this->assertSame(PlanResolution::REMOVED, $resolver->resolve('accounts', 2)->status);
        $this->assertSame(PlanResolution::REMOVED, $resolver->resolve('target_groups', 1)->status);
    }

    public function testUnknownKindIsKeptAsUnchecked(): void
    {
        $resolution = $this->resolver()->resolve('events', 4);

        $this->assertSame(PlanResolution::UNCHECKED, $resolution->status);
        $this->assertSame(4, $resolution->targetId);
    }

    public function testDescriptionsNameTheTarget(): void
    {
        $resolver = $this->resolver($this->pagesLookup());

        $this->assertSame('page "Leistungen (/leistungen)"', $resolver->describe('pages', self::PAGE));
        $this->assertSame('organization "1"', $resolver->describe('accounts', 1));
    }

    public function testAnswersAreRemembered(): void
    {
        $resolver = $this->resolver();
        $resolver->resolve('accounts', 1);
        $resolver->resolve('accounts', 1);

        $this->assertCount(1, $resolver->resolutions());
    }
}
