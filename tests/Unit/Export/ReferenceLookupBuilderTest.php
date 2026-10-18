<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Export;

use Manuxi\SuluContentTransferBundle\Export\LookupSource;
use Manuxi\SuluContentTransferBundle\Export\ReferenceLookupBuilder;
use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use PHPUnit\Framework\TestCase;

class ReferenceLookupBuilderTest extends TestCase
{
    private const SHA = 'c67b8cce5b5ee6c31919bf34e3fdd403d797bd4bef14578d52153f82ad9c862a';

    private static function source(): FakeLookupSource
    {
        return new FakeLookupSource(
            pages: [
                '22222222-2222-4222-8222-222222222222' => ['uuid' => '22222222-2222-4222-8222-222222222222', 'webspace' => 'your_webspace', 'urls' => ['de' => '/leistungen', 'en' => '/services'], 'titles' => ['de' => 'Leistungen', 'en' => 'Services']],
            ],
            snippets: [
                '66666666-6666-4666-8666-666666666666' => ['uuid' => '66666666-6666-4666-8666-666666666666', 'templateKey' => 'link', 'titles' => ['de' => 'Kontakt']],
            ],
            articles: [
                '33333333-3333-4333-8333-333333333333' => ['uuid' => '33333333-3333-4333-8333-333333333333', 'templateKey' => 'article', 'titles' => ['de' => 'Anderer Artikel'], 'urls' => ['de' => '/artikel/anderer']],
            ],
            categories: [
                248 => ['id' => 248, 'key' => 'child', 'names' => ['de' => 'Einsteiger', 'en' => 'Beginners'], 'path' => [['key' => 'root', 'names' => ['de' => 'Wurzel']]]],
            ],
            tags: [
                168 => ['id' => 168, 'name' => 'Export'],
                170 => ['id' => 170, 'name' => 'Import'],
            ],
            contacts: [
                3 => ['id' => 3, 'firstName' => 'Anna', 'lastName' => 'Muster', 'email' => 'anna@example.org'],
            ],
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: int|string}> $rows path, kind, id
     *
     * @return list<ArticleReference>
     */
    private static function references(array $rows): array
    {
        return array_map(static fn (array $row): ArticleReference => new ArticleReference('de', $row[0], $row[1], $row[2]), $rows);
    }

    public function testBuildsTheLookupForEveryKindOfReference(): void
    {
        $references = self::references([
            ['a', 'pages', '22222222-2222-4222-8222-222222222222'],
            ['b', 'pages', '22222222-2222-4222-8222-222222222222'],
            ['c', 'snippets', '66666666-6666-4666-8666-666666666666'],
            ['d', 'articles', '33333333-3333-4333-8333-333333333333'],
            ['e', 'categories', 248],
            ['f', 'tags', 168],
            ['g', 'tags', 'Import'],
            ['h', 'contacts', 3],
            ['i', 'media', 207],
        ]);
        $media = [['id' => 207, 'filename' => 'bild.jpg', 'size' => 12, 'sha256' => self::SHA]];

        $result = (new ReferenceLookupBuilder(self::source()))->build($references, [], $media);

        $lookup = $result->lookup;
        $this->assertSame(['22222222-2222-4222-8222-222222222222'], array_column($lookup->pages, 'uuid'));
        $this->assertSame(['de' => '/leistungen', 'en' => '/services'], $lookup->pages[0]['urls']);
        $this->assertSame('your_webspace', $lookup->pages[0]['webspace']);
        $this->assertSame(['66666666-6666-4666-8666-666666666666'], array_column($lookup->snippets, 'uuid'));
        $this->assertSame(['33333333-3333-4333-8333-333333333333'], array_column($lookup->articles, 'uuid'));
        $this->assertSame('child', $lookup->categories[0]['key']);
        $this->assertSame([['key' => 'root', 'names' => ['de' => 'Wurzel']]], $lookup->categories[0]['path']);
        $this->assertSame(['Export', 'Import'], array_column($lookup->tags, 'name'), 'tags by id and by name');
        $this->assertSame('anna@example.org', $lookup->contacts[0]['email']);
        $this->assertSame([207], array_column($lookup->media, 'id'));
        $this->assertSame([], $result->missing);
        $this->assertSame([], $result->withoutLookup);
    }

    public function testArticlesThatAreInThePackageAreNotLookedUp(): void
    {
        $source = self::source();
        $references = self::references([
            ['a', 'articles', '33333333-3333-4333-8333-333333333333'],
        ]);

        $result = (new ReferenceLookupBuilder($source))->build($references, ['33333333-3333-4333-8333-333333333333'], []);

        $this->assertSame([], $result->lookup->articles);
        $this->assertSame([], $result->missing);
        $this->assertArrayNotHasKey('articles', $source->calls);
    }

    public function testIdsTheSourceDoesNotKnowAreReported(): void
    {
        $references = self::references([
            ['a', 'pages', '99999999-9999-4999-8999-999999999999'],
            ['b', 'categories', 999],
            ['c', 'tags', 'Unknown'],
            ['d', 'contacts', 77],
            ['e', 'media', 5],
            ['f', 'snippets', '00000000-0000-4000-8000-000000000000'],
        ]);

        $result = (new ReferenceLookupBuilder(self::source()))->build($references, [], []);

        $this->assertSame([
            'pages' => ['99999999-9999-4999-8999-999999999999'],
            'snippets' => ['00000000-0000-4000-8000-000000000000'],
            'categories' => [999],
            'tags' => ['Unknown'],
            'contacts' => [77],
            'media' => [5],
        ], $result->missing);
    }

    public function testKindsWithoutLookupAreReportedAndNotQueried(): void
    {
        $references = self::references([
            ['a', 'accounts', 1],
            ['b', 'accounts', 1],
            ['c', 'collections', 26],
            ['d', 'target_groups', 4],
        ]);

        $result = (new ReferenceLookupBuilder(self::source()))->build($references, [], []);

        $this->assertSame(['accounts' => [1], 'collections' => [26], 'target_groups' => [4]], $result->withoutLookup);
        $this->assertSame([], $result->missing);
    }

    public function testNothingIsQueriedWithoutReferences(): void
    {
        $source = self::source();

        $result = (new ReferenceLookupBuilder($source))->build([], [], []);

        $this->assertSame([], $source->calls);
        $this->assertSame([], $result->lookup->pages);
    }

    public function testTheBuilderOnlyNeedsTheLookupSourceInterface(): void
    {
        $this->assertInstanceOf(LookupSource::class, self::source());
    }
}
