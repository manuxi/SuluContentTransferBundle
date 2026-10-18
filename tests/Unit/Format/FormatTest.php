<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Format;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\Manifest;
use Manuxi\SuluContentTransferBundle\Format\MediaMeta;
use Manuxi\SuluContentTransferBundle\Format\ReferenceLookup;
use Manuxi\SuluContentTransferBundle\Package\PackagePath;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function testReferenceLookupSortsItsEntriesAndKeepsExtraFields(): void
    {
        $lookup = new ReferenceLookup(
            tags: [['id' => 9, 'name' => 'Nine', 'note' => 'extra'], ['id' => 2, 'name' => 'Two']],
            pages: [
                ['uuid' => 'bbbbbbbb-0000-4000-8000-000000000001', 'webspace' => null, 'urls' => [], 'titles' => []],
                ['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'webspace' => null, 'urls' => [], 'titles' => []],
            ],
        );

        $this->assertSame([2, 9], array_column($lookup->tags, 'id'));
        $this->assertSame('extra', $lookup->tags[1]['note']);
        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $lookup->pages[0]['uuid']);

        $again = ReferenceLookup::fromArray(json_decode(json_encode($lookup->toArray()), true));
        $this->assertEquals($lookup, $again);
    }

    public function testReferenceLookupWritesEmptyMapsAsObjects(): void
    {
        $lookup = new ReferenceLookup(pages: [['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'webspace' => null, 'urls' => [], 'titles' => []]]);

        $this->assertInstanceOf(\stdClass::class, $lookup->toArray()['pages'][0]['urls']);
    }

    public function testReferenceLookupRefusesEntriesWithMissingOrWrongFields(): void
    {
        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessage('references.tags.0.name');

        new ReferenceLookup(tags: [['id' => 1]]);
    }

    public function testMediaMetaRefusesAnInvalidHash(): void
    {
        $this->expectException(InvalidPackageException::class);

        new MediaMeta('a.jpg', 'image/jpeg', 1, 'ABC');
    }

    public function testMediaMetaRoundTripsThroughItsArray(): void
    {
        ['package' => $package] = PackageFixtures::package(sys_get_temp_dir());
        $meta = $package->media[12]->meta;

        $this->assertEquals($meta, MediaMeta::fromArray(json_decode(json_encode($meta->toArray()), true)));
    }

    public function testArticleRefusesAnIdThatIsNoUuid(): void
    {
        $this->expectException(InvalidPackageException::class);

        ArticleEntry::fromArray(PackageFixtures::articleArray('not-a-uuid'));
    }

    public function testErrorsNameTheFaultyField(): void
    {
        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessage('source.instanceId');

        Manifest::fromArray(PackageFixtures::manifest(['source' => ['label' => 'x', 'suluVersion' => '3', 'bundleVersion' => '1']]));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function fileNames(): iterable
    {
        yield 'plain' => ['picture.jpg', true];
        yield 'umlauts and spaces' => ['Bild äö 1.jpg', true];
        yield 'no extension' => ['LICENSE', true];
        yield 'empty' => ['', false];
        yield 'slash' => ['a/b.jpg', false];
        yield 'backslash' => ['a\\b.jpg', false];
        yield 'parent directory' => ['..', false];
        yield 'hidden' => ['.htaccess', false];
        yield 'colon' => ['a:b.jpg', false];
        yield 'control character' => ["a\x01.jpg", false];
        yield 'null byte' => ["a\0.jpg", false];
        yield 'trailing dot' => ['a.', false];
        yield 'trailing space' => ['a.jpg ', false];
        yield 'windows device' => ['CON.txt', false];
        yield 'windows device in lower case' => ['nul', false];
        yield 'reserved meta file' => ['meta.json', false];
        yield 'invalid utf-8' => ["a\xff.jpg", false];
        yield 'too long' => [str_repeat('a', 256), false];
        yield 'longest allowed' => [str_repeat('a', 255), true];
    }

    /**
     * @dataProvider fileNames
     */
    public function testFileNames(string $name, bool $safe): void
    {
        $this->assertSame($safe, PackagePath::isSafeFileName($name));
    }

    public function testPathsAreClassified(): void
    {
        $uuid = PackageFixtures::ARTICLE_1;

        $this->assertSame('manifest', PackagePath::classify('manifest.json')['kind']);
        $this->assertSame(['kind' => 'article', 'id' => $uuid, 'file' => null], PackagePath::classify('articles/'.$uuid.'.json'));
        $this->assertSame(['kind' => 'media_meta', 'id' => '12', 'file' => 'meta.json'], PackagePath::classify('media/12/meta.json'));
        $this->assertSame(['kind' => 'media_file', 'id' => '12', 'file' => 'a b.jpg'], PackagePath::classify('media/12/a b.jpg'));
        $this->assertSame('directory', PackagePath::classify('media/12/')['kind']);
        $this->assertNull(PackagePath::classify("media/12/a.jpg\n"));
        $this->assertNull(PackagePath::classify("manifest.json\n"));
    }
}
