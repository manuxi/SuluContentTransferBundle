<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Support;

use Manuxi\SuluContentTransferBundle\Format\ArticleDimension;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use Manuxi\SuluContentTransferBundle\Format\ManifestItem;

/**
 * Builds the parts of a package the import analysis works on.
 */
final class ImportFixtures
{
    public const UUID = '0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8';
    public const PAGE = 'aaaaaaaa-0000-4000-8000-000000000001';

    /**
     * @param array<string, array<string, mixed>> $data       extra fields of the article data per language (default: de and en)
     * @param list<ArticleReference>              $references
     */
    public static function entry(string $uuid = self::UUID, array $data = [], array $references = [], string $stage = 'live', string $changed = '2026-10-17T09:00:00+00:00'): ArticleEntry
    {
        $data = [] === $data ? ['de' => [], 'en' => []] : $data;

        $dimensions = [];
        foreach ($data as $locale => $extra) {
            $dimensions[] = new ArticleDimension($locale, $stage, 'live' === $stage, 'live' === $stage ? new \DateTimeImmutable($changed) : null, array_replace([
                'template' => 'tutorial',
                'title' => 'Title '.$locale,
                'url' => ['page' => ['uuid' => self::PAGE, 'path' => '/source/'.$locale], 'suffix' => '/article-'.$locale],
                'mainWebspace' => 'your_webspace',
                'changed' => $changed,
            ], $extra));
        }

        return new ArticleEntry($uuid, new \DateTimeImmutable($changed), $dimensions, $references);
    }

    public static function item(string $uuid = self::UUID, string $templateKey = 'tutorial'): ManifestItem
    {
        return new ManifestItem('articles', $uuid, $templateKey, ['de' => 'Titel', 'en' => 'Title']);
    }
}
