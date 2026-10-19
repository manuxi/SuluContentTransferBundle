<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Rewrite;

use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\ReferenceRewriter;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\Rewrite;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\RewriteMap;
use PHPUnit\Framework\TestCase;

final class ReferenceRewriterTest extends TestCase
{
    private const PAGE_A = 'aaaaaaaa-0000-4000-8000-00000000000a';
    private const PAGE_B = 'bbbbbbbb-0000-4000-8000-00000000000b';
    private const ARTICLE = 'cccccccc-0000-4000-8000-00000000000c';

    /**
     * @param array<string, int|string> $replace "kind:id" => new value
     * @param list<string>              $remove  "kind:id"
     */
    private function map(array $replace = [], array $remove = []): RewriteMap
    {
        return new class($replace, $remove) implements RewriteMap {
            /**
             * @param array<string, int|string> $replace "kind:id" => new value
             * @param list<string>              $remove  "kind:id"
             */
            public function __construct(private readonly array $replace, private readonly array $remove)
            {
            }

            public function resolve(string $kind, int|string $sourceId): Rewrite
            {
                $key = $kind.':'.$sourceId;
                if (isset($this->replace[$key])) {
                    return Rewrite::replace($this->replace[$key]);
                }

                return \in_array($key, $this->remove, true) ? Rewrite::remove() : Rewrite::keep();
            }
        };
    }

    private function reference(string $path, string $kind, int|string $id, string $locale = 'de'): ArticleReference
    {
        return new ArticleReference($locale, $path, $kind, $id);
    }

    public function testReplacesIdsInListsObjectsAndSingleValues(): void
    {
        $data = [
            'gallery' => ['ids' => [207, 208], 'displayOption' => 'left'],
            'heroImage' => ['id' => 209],
            'mainPage' => self::PAGE_A,
            'topics' => [248],
            'author' => 3,
            'sections' => [['type' => 'text-image', 'sectionImage' => ['id' => 207]]],
        ];
        $references = [
            $this->reference('gallery.ids.0', 'media', 207),
            $this->reference('gallery.ids.1', 'media', 208),
            $this->reference('heroImage.id', 'media', 209),
            $this->reference('mainPage', 'pages', self::PAGE_A),
            $this->reference('topics.0', 'categories', 248),
            $this->reference('author', 'contacts', 3),
            $this->reference('sections.0.sectionImage.id', 'media', 207),
        ];
        $map = $this->map([
            'media:207' => 301, 'media:208' => 302, 'media:209' => 303,
            'pages:'.self::PAGE_A => self::PAGE_B,
            'categories:248' => 15,
            'contacts:3' => 9,
        ]);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        $this->assertSame([301, 302], $result->data['gallery']['ids']);
        $this->assertSame('left', $result->data['gallery']['displayOption']);
        $this->assertSame(['id' => 303], $result->data['heroImage']);
        $this->assertSame(self::PAGE_B, $result->data['mainPage']);
        $this->assertSame([15], $result->data['topics']);
        $this->assertSame(9, $result->data['author']);
        $this->assertSame(301, $result->data['sections'][0]['sectionImage']['id']);
        $this->assertSame([], $result->removed);
    }

    public function testRemovesListElementsAndSingleValues(): void
    {
        $data = [
            'gallery' => ['ids' => [207, 208, 209], 'displayOption' => 'left'],
            'heroImage' => ['id' => 210],
            'mainPage' => self::PAGE_A,
            'relatedPages' => [self::PAGE_A, self::PAGE_B],
            'excerpt' => ['title' => 'T', 'image' => ['id' => 207], 'icon' => ['id' => 208]],
            'author' => 3,
        ];
        $references = [
            $this->reference('gallery.ids.0', 'media', 207),
            $this->reference('gallery.ids.2', 'media', 209),
            $this->reference('heroImage.id', 'media', 210),
            $this->reference('mainPage', 'pages', self::PAGE_A),
            $this->reference('relatedPages.0', 'pages', self::PAGE_A),
            $this->reference('relatedPages.1', 'pages', self::PAGE_B),
            $this->reference('excerpt.image.id', 'media', 207),
            $this->reference('excerpt.icon.id', 'media', 208),
            $this->reference('author', 'contacts', 3),
        ];
        $map = $this->map(['media:208' => 400], ['media:207', 'media:209', 'media:210', 'pages:'.self::PAGE_A, 'contacts:3']);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        // 207 and 209 were removed; the list stays a list without gaps
        $this->assertSame([208], $result->data['gallery']['ids']);
        $this->assertNull($result->data['heroImage']);
        $this->assertNull($result->data['mainPage']);
        $this->assertSame([self::PAGE_B], $result->data['relatedPages']);
        $this->assertNull($result->data['excerpt']['image']);
        $this->assertSame(['id' => 400], $result->data['excerpt']['icon']);
        $this->assertNull($result->data['author']);
        $this->assertCount(7, $result->removed);
    }

    public function testRemovingAnItemOfAListOfObjectsRemovesTheWholeItem(): void
    {
        $data = ['teasers' => ['presentAs' => 'two', 'items' => [
            ['id' => self::PAGE_A, 'type' => 'pages'],
            ['id' => self::ARTICLE, 'type' => 'articles'],
            ['id' => self::PAGE_B, 'type' => 'pages'],
        ]]];
        $references = [
            $this->reference('teasers.items.0.id', 'pages', self::PAGE_A),
            $this->reference('teasers.items.1.id', 'articles', self::ARTICLE),
            $this->reference('teasers.items.2.id', 'pages', self::PAGE_B),
        ];
        $map = $this->map(['articles:'.self::ARTICLE => 'dddddddd-0000-4000-8000-00000000000d'], ['pages:'.self::PAGE_A, 'pages:'.self::PAGE_B]);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        $this->assertSame([['id' => 'dddddddd-0000-4000-8000-00000000000d', 'type' => 'articles']], $result->data['teasers']['items']);
        $this->assertSame('two', $result->data['teasers']['presentAs']);
    }

    public function testRemovingAnItemInsideARemovedBlockDoesNotShiftTheOthers(): void
    {
        $data = ['sections' => [
            ['type' => 'a', 'image' => ['id' => 1]],
            ['type' => 'b', 'images' => ['ids' => [2, 3, 4]]],
            ['type' => 'c', 'image' => ['id' => 5]],
        ]];
        $references = [
            $this->reference('sections.0.image.id', 'media', 1),
            $this->reference('sections.1.images.ids.0', 'media', 2),
            $this->reference('sections.1.images.ids.2', 'media', 4),
            $this->reference('sections.2.image.id', 'media', 5),
        ];
        $map = $this->map(['media:3' => 33, 'media:5' => 55], ['media:1', 'media:2', 'media:4']);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        $this->assertNull($result->data['sections'][0]['image']);
        $this->assertSame([3], $result->data['sections'][1]['images']['ids']);
        $this->assertSame(['id' => 55], $result->data['sections'][2]['image']);
    }

    public function testLinkFieldsAreRewrittenOrEmptied(): void
    {
        $data = [
            'cta' => ['provider' => 'page', 'href' => self::PAGE_A, 'target' => '_self', 'title' => 'Portfolio'],
            'sections' => [['type' => 'gallery', 'target' => ['provider' => 'article', 'href' => self::ARTICLE, 'target' => '_self']]],
        ];
        $references = [
            $this->reference('cta.href', 'pages', self::PAGE_A),
            $this->reference('sections.0.target.href', 'articles', self::ARTICLE),
        ];
        $map = $this->map(['pages:'.self::PAGE_A => self::PAGE_B], ['articles:'.self::ARTICLE]);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        $this->assertSame(self::PAGE_B, $result->data['cta']['href']);
        $this->assertSame('Portfolio', $result->data['cta']['title']);
        $this->assertNull($result->data['sections'][0]['target']);
    }

    public function testSuluLinksInTextAreRewrittenAndUnwrappedWhenTheTargetIsGone(): void
    {
        $text = '<p>See <sulu-link provider="page" href="'.self::PAGE_A.'" target="_self" title="A">page A</sulu-link>, '
            .'<sulu-link provider="article" href="'.self::ARTICLE.'" target="_self">the article</sulu-link>, '
            .'<sulu-link provider="media" href="208" target="_blank">download</sulu-link> and '
            .'<sulu-link provider="external" href="https://example.org">outside</sulu-link>.</p>';
        $data = ['body' => $text];
        $references = [
            $this->reference('body', 'pages', self::PAGE_A),
            $this->reference('body', 'articles', self::ARTICLE),
            $this->reference('body', 'media', 208),
        ];
        $map = $this->map(['pages:'.self::PAGE_A => self::PAGE_B, 'media:208' => 308], ['articles:'.self::ARTICLE]);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        $this->assertSame(
            '<p>See <sulu-link provider="page" href="'.self::PAGE_B.'" target="_self" title="A">page A</sulu-link>, '
            .'the article, '
            .'<sulu-link provider="media" href="308" target="_blank">download</sulu-link> and '
            .'<sulu-link provider="external" href="https://example.org">outside</sulu-link>.</p>',
            $result->data['body'],
        );
        $this->assertCount(1, $result->removed);
        $this->assertSame('articles', $result->removed[0]->kind);
        $this->assertSame('body', $result->removed[0]->path);
    }

    public function testRewritesOneTextOnlyOnceEvenWhenSeveralTargetsPointToIt(): void
    {
        // A -> B and B -> C at the same time must not turn A into C
        $text = '<sulu-link provider="page" href="'.self::PAGE_A.'">a</sulu-link><sulu-link provider="page" href="'.self::PAGE_B.'">b</sulu-link>';
        $references = [$this->reference('body', 'pages', self::PAGE_A), $this->reference('body', 'pages', self::PAGE_B)];
        $map = $this->map(['pages:'.self::PAGE_A => self::PAGE_B, 'pages:'.self::PAGE_B => self::ARTICLE]);

        $result = (new ReferenceRewriter())->rewrite('de', ['body' => $text], $references, $map);

        $this->assertSame(
            '<sulu-link provider="page" href="'.self::PAGE_B.'">a</sulu-link><sulu-link provider="page" href="'.self::ARTICLE.'">b</sulu-link>',
            $result->data['body'],
        );
    }

    public function testSmartContentFiltersAndDataSource(): void
    {
        $data = [
            'articleListing' => ['categories' => [248, 249], 'tags' => ['Some tag'], 'sortBy' => 'title'],
            'pageListing' => ['dataSource' => self::PAGE_A, 'categories' => [247], 'sortBy' => 'title'],
        ];
        $references = [
            $this->reference('articleListing.categories.0', 'categories', 248),
            $this->reference('articleListing.categories.1', 'categories', 249),
            $this->reference('articleListing.tags.0', 'tags', 'Some tag'),
            $this->reference('pageListing.dataSource', 'pages', self::PAGE_A),
            $this->reference('pageListing.categories.0', 'categories', 247),
        ];
        $map = $this->map(['categories:248' => 18, 'categories:247' => 17], ['categories:249', 'pages:'.self::PAGE_A, 'tags:Some tag']);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        // the tag filter holds names and stays; a missing category leaves the filter
        $this->assertSame([18], $result->data['articleListing']['categories']);
        $this->assertSame(['Some tag'], $result->data['articleListing']['tags']);
        // without its data source the whole selection is an empty one, never null
        $this->assertSame([], $result->data['pageListing']);
    }

    public function testContactAndAccountValuesWithPrefix(): void
    {
        $data = ['people' => ['c12', 'a3']];
        $references = [$this->reference('people.0', 'contacts', 12), $this->reference('people.1', 'accounts', 3)];
        $map = $this->map(['contacts:12' => 40], ['accounts:3']);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        $this->assertSame(['c40'], $result->data['people']);
    }

    public function testKeepsWhatTheMapKeepsAndIgnoresOtherLanguagesAndMissingPaths(): void
    {
        $data = ['mainPage' => self::PAGE_A, 'gallery' => ['ids' => [1]]];
        $references = [
            $this->reference('mainPage', 'pages', self::PAGE_A),
            $this->reference('gallery.ids.0', 'media', 1, 'en'),
            $this->reference('nothing.here', 'media', 5),
        ];
        $map = $this->map(['media:1' => 99, 'media:5' => 6], ['pages:'.self::PAGE_B]);

        $result = (new ReferenceRewriter())->rewrite('de', $data, $references, $map);

        $this->assertSame($data, $result->data);
        $this->assertSame([], $result->removed);
    }

    public function testEmptyObjectsBecomeEmptyArrays(): void
    {
        $data = ['pageListing' => new \stdClass(), 'seo' => ['title' => 'x']];

        $result = (new ReferenceRewriter())->rewrite('de', $data, [], $this->map());

        $this->assertSame([], $result->data['pageListing']);
    }
}
