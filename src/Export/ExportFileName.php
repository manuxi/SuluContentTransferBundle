<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

/**
 * Name of the package file of an export: "<date>-<slug of the first title>.sulu-transfer.zip".
 */
final class ExportFileName
{
    private const EXTENSION = '.sulu-transfer.zip';

    /**
     * @param list<ExportedArticle> $articles
     */
    public static function for(array $articles, ?\DateTimeInterface $date = null): string
    {
        $title = '';
        foreach ($articles as $article) {
            if ('' !== $article->title) {
                $title = $article->title;

                break;
            }
        }

        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', self::transliterate($title)), '-'));
        $slug = '' === $slug ? 'articles' : substr($slug, 0, 60);

        return ($date ?? new \DateTimeImmutable())->format('Y-m-d').'-'.$slug.self::EXTENSION;
    }

    private static function transliterate(string $text): string
    {
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);

        return false === $converted ? $text : $converted;
    }
}
