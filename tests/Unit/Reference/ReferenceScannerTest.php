<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Reference;

use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use Manuxi\SuluContentTransferBundle\Reference\ReferenceScanner;
use Manuxi\SuluContentTransferBundle\Reference\TemplateSchema;
use PHPUnit\Framework\TestCase;

class ReferenceScannerTest extends TestCase
{
    private const PAGE_A = '22222222-2222-4222-8222-222222222222';
    private const PAGE_B = '55555555-5555-4555-8555-555555555555';
    private const ARTICLE = '33333333-3333-4333-8333-333333333333';
    private const SNIPPET = '66666666-6666-4666-8666-666666666666';
    private const PARENT_PAGE = '11111111-1111-4111-8111-111111111111';

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/' . $name), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param list<ArticleReference> $references
     *
     * @return list<string> "path | resourceKey | id", sorted
     */
    private static function lines(array $references): array
    {
        $lines = array_map(static fn (ArticleReference $r): string => $r->path . ' | ' . $r->resourceKey . ' | ' . $r->id, $references);
        sort($lines, \SORT_STRING);

        return $lines;
    }

    public function testFindsEveryKindOfReferenceInTheAdminForm(): void
    {
        $references = (new ReferenceScanner())->scan(
            'en',
            self::fixture('reference-test.form.json'),
            TemplateSchema::fromArray(self::fixture('reference-test.schema.json')),
        );

        $expected = [
            'author | contacts | 3',
            'url.page.uuid | pages | ' . self::PARENT_PAGE,
            'image.id | media | 210',
            // text editor: one entry per different link, the same link twice counts once; external links are ignored
            'body | pages | ' . self::PAGE_A,
            'body | articles | ' . self::ARTICLE,
            'body | media | 208',
            // link field
            'cta.href | pages | ' . self::PAGE_B,
            // media
            'gallery.ids.0 | media | 207',
            'gallery.ids.1 | media | 208',
            'heroImage.id | media | 209',
            // pages, articles, snippets
            'relatedPages.0 | pages | ' . self::PAGE_A,
            'relatedPages.1 | pages | ' . self::PAGE_B,
            'mainPage | pages | ' . self::PAGE_A,
            'relatedArticles.0 | articles | ' . self::ARTICLE,
            'mainArticle | articles | ' . self::ARTICLE,
            'snippets.0 | snippets | ' . self::SNIPPET,
            'mainSnippet | snippets | ' . self::SNIPPET,
            // categories, tags, contacts, accounts
            'topics.0 | categories | 248',
            'mainTopic | categories | 247',
            'keywords.0 | tags | 168',
            'reviewers.0 | contacts | 2',
            'reviewers.1 | contacts | 3',
            'mainContact | contacts | 3',
            'organisations.0 | accounts | 1',
            'mainOrganisation | accounts | 1',
            'contactsAndAccounts.0 | contacts | 2',
            'contactsAndAccounts.1 | accounts | 1',
            // media folders
            'folders.0 | collections | 26',
            'mainFolder | collections | 27',
            // teasers (only pages and articles are known kinds)
            'teasers.items.0.id | pages | ' . self::PAGE_A,
            'teasers.items.1.id | articles | ' . self::ARTICLE,
            // smart content: categories, tag names, data source by provider
            'articleListing.categories.0 | categories | 248',
            'articleListing.tags.0 | tags | transfer',
            'articleListing.tags.1 | tags | export',
            'pageListing.categories.0 | categories | 247',
            'pageListing.dataSource | pages | ' . self::PAGE_A,
            'mediaListing.dataSource | collections | 26',
            // excerpt
            'excerpt.image.id | media | 208',
            'excerpt.icon.id | media | 207',
            'excerpt.description | pages | ' . self::PAGE_A,
            'excerptCategories.0 | categories | 248',
            'excerptTags.0 | tags | 168',
            'excerptTags.1 | tags | 169',
            'excerptAudienceTargetGroups.0 | target_groups | 4',
            // blocks, also nested ones; a removed block type and empty values are skipped
            'sections.0.sectionText | pages | ' . self::PAGE_B,
            'sections.0.sectionImage.id | media | 207',
            'sections.1.images.ids.0 | media | 209',
            'sections.1.images.ids.1 | media | 210',
            'sections.1.target.href | articles | ' . self::ARTICLE,
            'sections.1.inner.0.itemImage.id | media | 208',
        ];
        sort($expected, \SORT_STRING);

        $this->assertSame($expected, self::lines($references));
        foreach ($references as $reference) {
            $this->assertSame('en', $reference->locale);
        }
    }

    public function testTextOfOtherFieldTypesIsNotSearchedForLinks(): void
    {
        $references = (new ReferenceScanner())->scan(
            'de',
            ['intro' => '<sulu-link provider="page" href="' . self::PAGE_A . '">x</sulu-link>'],
            TemplateSchema::fromArray(['intro' => ['type' => 'text_area']]),
        );

        $this->assertSame([], $references);
    }

    public function testUnknownFieldTypesAndMissingValuesAreSkipped(): void
    {
        $references = (new ReferenceScanner())->scan(
            'de',
            ['color' => 'red', 'someThing' => ['id' => 5], 'mainPage' => null, 'topics' => null, 'gallery' => null, 'sections' => 'broken'],
            TemplateSchema::fromArray([
                'color' => ['type' => 'color'],
                'someThing' => ['type' => 'third_party_field'],
                'mainPage' => ['type' => 'single_page_selection'],
                'topics' => ['type' => 'category_selection'],
                'gallery' => ['type' => 'media_selection'],
                'missing' => ['type' => 'single_media_selection'],
                'sections' => ['type' => 'block'],
            ]),
        );

        $this->assertSame([], $references);
    }

    public function testMediaSelectionAsPlainListIsUnderstood(): void
    {
        $references = (new ReferenceScanner())->scan(
            'de',
            ['gallery' => [5, 6]],
            TemplateSchema::fromArray(['gallery' => ['type' => 'media_selection']]),
        );

        $this->assertSame(['gallery.0 | media | 5', 'gallery.1 | media | 6'], self::lines($references));
    }

    public function testLinkProviderAndDataSourceOfUnknownKindAreIgnored(): void
    {
        $references = (new ReferenceScanner())->scan(
            'de',
            [
                'cta' => ['provider' => 'external', 'href' => 'https://example.org'],
                'mail' => ['provider' => 'page'],
                'list' => ['dataSource' => 'abc', 'categories' => [], 'tags' => ['', 'Tip']],
            ],
            TemplateSchema::fromArray([
                'cta' => ['type' => 'link'],
                'mail' => ['type' => 'link'],
                'list' => ['type' => 'smart_content', 'params' => ['provider' => 'events']],
            ]),
        );

        $this->assertSame(['list.tags.1 | tags | Tip'], self::lines($references));
    }

    public function testMediaIdsInLinksBecomeNumbers(): void
    {
        $references = (new ReferenceScanner())->scan(
            'de',
            ['cta' => ['provider' => 'media', 'href' => '17'], 'body' => '<sulu-link provider="media" href="18">x</sulu-link>'],
            TemplateSchema::fromArray(['cta' => ['type' => 'link'], 'body' => ['type' => 'text_editor']]),
        );

        $byPath = [];
        foreach ($references as $reference) {
            $byPath[$reference->path] = $reference->id;
        }
        $this->assertSame(['body' => 18, 'cta.href' => 17], $byPath);
    }

    public function testResultIsSortedAndFreeOfDuplicates(): void
    {
        $scanner = new ReferenceScanner();
        $data = ['b' => self::PAGE_A, 'a' => self::PAGE_A, 'body' => '<sulu-link provider="page" href="' . self::PAGE_A . '"></sulu-link><sulu-link provider="page" href="' . self::PAGE_A . '"></sulu-link>'];
        $schema = TemplateSchema::fromArray(['b' => ['type' => 'single_page_selection'], 'a' => ['type' => 'single_page_selection'], 'body' => ['type' => 'text_editor']]);

        $first = $scanner->scan('de', $data, $schema);

        $this->assertSame(['a', 'b', 'body'], array_map(static fn (ArticleReference $r): string => $r->path, $first));
        $this->assertEquals($first, $scanner->scan('de', $data, $schema));
    }
}
