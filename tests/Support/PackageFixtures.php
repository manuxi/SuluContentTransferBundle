<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Support;

use Manuxi\SuluContentTransferBundle\Format\ArticleDimension;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use Manuxi\SuluContentTransferBundle\Format\Manifest;
use Manuxi\SuluContentTransferBundle\Format\ManifestItem;
use Manuxi\SuluContentTransferBundle\Format\ManifestOptions;
use Manuxi\SuluContentTransferBundle\Format\ManifestSource;
use Manuxi\SuluContentTransferBundle\Format\MediaEntry;
use Manuxi\SuluContentTransferBundle\Format\MediaMeta;
use Manuxi\SuluContentTransferBundle\Format\Package;
use Manuxi\SuluContentTransferBundle\Format\ReferenceLookup;

/**
 * Builds packages and package parts for the tests.
 */
final class PackageFixtures
{
    public const ARTICLE_1 = '0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8';
    public const ARTICLE_2 = 'f1e2d3c4-b5a6-4978-8a9b-0c1d2e3f4a5b';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function manifest(array $overrides = []): array
    {
        return array_replace([
            'format' => Manifest::FORMAT,
            'formatVersion' => Manifest::FORMAT_VERSION,
            'createdAt' => '2026-10-18T15:30:00+02:00',
            'source' => [
                'instanceId' => '0123456789abcdef',
                'label' => 'local.example.org',
                'suluVersion' => '3.0.9',
                'bundleVersion' => '1.0.0',
                'user' => 'admin',
            ],
            'options' => ['locales' => ['de', 'en'], 'stage' => 'live', 'includeMediaFiles' => true],
            'items' => [
                ['resourceKey' => 'articles', 'id' => self::ARTICLE_1, 'templateKey' => 'tutorial', 'titles' => ['de' => 'Erste Schritte', 'en' => 'First steps']],
            ],
            'references' => [
                'pages' => [], 'snippets' => [], 'articles' => [], 'categories' => [], 'tags' => [], 'contacts' => [], 'media' => [],
            ],
        ], $overrides);
    }

    /**
     * A package with two languages, references of several kinds, one medium (with a file) and unusual content in the
     * article data: umlauts, slashes, an empty object, a float.
     *
     * @return array{package: Package, mediaFiles: array<int, string>}
     */
    public static function package(string $directory, bool $withMedia = true): array
    {
        $content = "not really a jpeg \x00\x01\xff binary";
        $mediaFile = $directory.'/source-image.jpg';
        file_put_contents($mediaFile, $content);

        $lookup = new ReferenceLookup(
            pages: [['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'webspace' => 'your_webspace', 'urls' => ['de' => '/leistungen', 'en' => '/services'], 'titles' => ['de' => 'Leistungen', 'en' => 'Services']]],
            snippets: [['uuid' => 'bbbbbbbb-0000-4000-8000-000000000002', 'templateKey' => 'default', 'titles' => ['de' => 'Fußzeile']]],
            articles: [['uuid' => self::ARTICLE_2, 'templateKey' => 'tutorial', 'titles' => ['de' => 'Nicht exportiert'], 'urls' => ['de' => '/einblicke/nicht-exportiert']]],
            categories: [['id' => 7, 'key' => 'beginners', 'names' => ['de' => 'Einsteiger', 'en' => 'Beginners'], 'path' => [['key' => 'tutorials', 'names' => ['de' => 'Tutorials', 'en' => 'Tutorials']]]]],
            tags: [['id' => 3, 'name' => 'Sulu']],
            contacts: [['id' => 5, 'firstName' => 'Erika', 'lastName' => 'Mustermann', 'email' => 'erika@example.org']],
            media: [['id' => 12, 'filename' => 'Bild äö.jpg', 'size' => \strlen($content), 'sha256' => hash('sha256', $content)]],
        );

        $manifest = new Manifest(
            new \DateTimeImmutable('2026-10-18T15:30:00+02:00'),
            new ManifestSource('0123456789abcdef', 'local.example.org', '3.0.9', '1.0.0', 'admin'),
            new ManifestOptions(['en', 'de'], 'live', $withMedia),
            [new ManifestItem('articles', self::ARTICLE_1, 'tutorial', ['en' => 'First steps', 'de' => 'Erste Schritte'])],
            $lookup,
        );

        $article = new ArticleEntry(
            self::ARTICLE_1,
            new \DateTimeImmutable('2026-10-17T09:00:00+00:00'),
            [
                new ArticleDimension('en', 'live', true, new \DateTimeImmutable('2026-10-17T09:00:00+00:00'), [
                    'template' => 'tutorial',
                    'title' => 'First steps',
                    'url' => '/insights/first-steps',
                ]),
                new ArticleDimension('de', 'draft', false, null, [
                    'template' => 'tutorial',
                    'title' => 'Erste Schritte: Übersicht & Größe',
                    'url' => '/einblicke/erste-schritte',
                    'ratio' => 1.0,
                    'selection' => new \stdClass(),
                    'blocks' => [['type' => 'image', 'image' => ['id' => 12], 'link' => 'https://example.org/a/b']],
                ]),
            ],
            [
                new ArticleReference('de', 'blocks.0.image', 'media', 12),
                new ArticleReference('de', 'excerptCategories.0', 'categories', 7),
                new ArticleReference('en', 'link', 'pages', 'aaaaaaaa-0000-4000-8000-000000000001'),
            ],
        );

        $media = [];
        $mediaFiles = [];
        if ($withMedia) {
            $media[] = new MediaEntry(12, new MediaMeta(
                'Bild äö.jpg',
                'image/jpeg',
                \strlen($content),
                hash('sha256', $content),
                ['de' => ['title' => 'Ein Bild', 'description' => null, 'copyright' => 'Example', 'credits' => null]],
                ['x' => 40, 'y' => 60],
                ['sulu-100x100' => ['cropX' => 1, 'cropY' => 2, 'cropWidth' => 3, 'cropHeight' => 4]],
                ['Sulu', 'Tutorial'],
                [['key' => 'beginners', 'names' => ['de' => 'Einsteiger']]],
                ['source' => 'camera'],
                ['key' => 'tutorials', 'titlePath' => [['de' => 'Tutorials', 'en' => 'Tutorials']]],
            ), 'Bild äö.jpg');
            $mediaFiles[12] = $mediaFile;
        }

        return ['package' => new Package($manifest, [$article], $media), 'mediaFiles' => $mediaFiles];
    }

    /**
     * Content of an article file as it would be written by the exporter.
     *
     * @return array<string, mixed>
     */
    public static function articleArray(string $uuid = self::ARTICLE_1): array
    {
        return [
            'uuid' => $uuid,
            'changed' => '2026-10-17T09:00:00+00:00',
            'dimensions' => ['de' => ['stage' => 'live', 'published' => true, 'publishedAt' => '2026-10-17T09:00:00+00:00', 'data' => ['title' => 'Erste Schritte']]],
            'references' => [],
        ];
    }
}
