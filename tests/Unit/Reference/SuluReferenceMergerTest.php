<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Reference;

use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use Manuxi\SuluContentTransferBundle\Reference\SuluReferenceMerger;
use PHPUnit\Framework\TestCase;

class SuluReferenceMergerTest extends TestCase
{
    /**
     * @param list<ArticleReference> $references
     *
     * @return list<string>
     */
    private static function lines(array $references): array
    {
        return array_map(static fn (ArticleReference $r): string => $r->path.' | '.$r->resourceKey.' | '.$r->id, $references);
    }

    public function testTranslatesThePathsOfSuluToThePathsOfTheAdminForm(): void
    {
        $data = [
            'author' => 3,
            'excerptCategories' => [248],
            'excerptTags' => [168, 169],
            'excerpt' => ['image' => ['id' => 208]],
            'gallery' => ['ids' => [207, 208], 'displayOption' => 'left'],
            'sections' => [['type' => 'x', 'images' => ['ids' => [5]]]],
        ];
        $sulu = [
            ['resolver' => 'settings', 'path' => 'settings.author', 'resourceKey' => 'users', 'id' => 3],
            ['resolver' => 'excerpt', 'path' => 'excerpt.categories', 'resourceKey' => 'categories', 'id' => 248],
            ['resolver' => 'excerpt', 'path' => 'excerpt.tags', 'resourceKey' => 'tags', 'id' => 169],
            ['resolver' => 'excerpt', 'path' => 'excerpt.image', 'resourceKey' => 'media', 'id' => 208],
            ['resolver' => 'template', 'path' => 'gallery', 'resourceKey' => 'media', 'id' => 208],
            ['resolver' => 'template', 'path' => 'sections.0.images', 'resourceKey' => 'media', 'id' => 5],
        ];

        $result = (new SuluReferenceMerger())->merge('de', $data, [], $sulu);

        $this->assertSame([
            'author | contacts | 3',
            'excerpt.image.id | media | 208',
            'excerptCategories.0 | categories | 248',
            'excerptTags.1 | tags | 169',
            'gallery.ids.1 | media | 208',
            'sections.0.images.ids.0 | media | 5',
        ], self::lines($result['references']));
    }

    public function testKeepsWhatOnlySuluKnowsAndDoesNotDuplicateWhatTheScannerFound(): void
    {
        $data = ['cardImage' => ['id' => 7], 'mainPage' => 'abc'];
        $scanned = [new ArticleReference('de', 'mainPage', 'pages', 'abc')];
        $sulu = [
            ['resolver' => 'template', 'path' => 'mainPage', 'resourceKey' => 'pages', 'id' => 'abc'],
            ['resolver' => 'template', 'path' => 'cardImage', 'resourceKey' => 'media', 'id' => 7],
        ];

        $result = (new SuluReferenceMerger())->merge('de', $data, $scanned, $sulu);

        $this->assertSame(['cardImage.id | media | 7', 'mainPage | pages | abc'], self::lines($result['references']));
        $this->assertSame(['cardImage.id | media | 7'], self::lines($result['onlyInSulu']));
    }

    public function testAPathMissingInTheFormIsKeptAsItIs(): void
    {
        $result = (new SuluReferenceMerger())->merge('de', [], [], [
            ['resolver' => 'template', 'path' => 'gone.0.image', 'resourceKey' => 'media', 'id' => 4],
        ]);

        $this->assertSame(['gone.0.image | media | 4'], self::lines($result['references']));
    }

    public function testTranslatePath(): void
    {
        $this->assertSame('author', SuluReferenceMerger::translatePath('settings', 'settings.author'));
        $this->assertSame('excerptTags', SuluReferenceMerger::translatePath('excerpt', 'excerpt.tags'));
        $this->assertSame('excerpt.icon', SuluReferenceMerger::translatePath('excerpt', 'excerpt.icon'));
        $this->assertSame('blocks.0.image', SuluReferenceMerger::translatePath('template', 'blocks.0.image'));
    }
}
