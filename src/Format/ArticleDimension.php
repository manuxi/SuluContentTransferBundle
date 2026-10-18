<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

/**
 * One language of an article: the version that was exported (live or draft) in the admin normal form, i.e. what
 * "GET /admin/api/articles/<id>?locale=xx" delivers and what "ModifyArticleMessage" accepts (template, title, url,
 * template fields, excerpt*, seo*, author, authored, mainWebspace, shadowOn, shadowLocale, ...).
 */
final class ArticleDimension
{
    /**
     * @param 'live'|'draft'           $stage       version the data was taken from
     * @param bool                     $published   whether the source had a published version of this language
     * @param array<int|string, mixed> $data        admin normal form
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $stage,
        public readonly bool $published,
        public readonly ?\DateTimeImmutable $publishedAt,
        public readonly array $data,
    ) {
        Data::assertSupportedStage($stage, 'article.dimensions.'.$locale.'.stage');
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(string $locale, array $data): self
    {
        $path = 'article.dimensions.'.$locale;
        $stage = Data::string($data, 'stage', $path);
        Data::assertSupportedStage($stage, $path.'.stage');

        return new self(
            $locale,
            $stage,
            Data::bool($data, 'published', $path),
            Data::nullableDateTime($data, 'publishedAt', $path),
            Data::map($data, 'data', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'published' => $this->published,
            'publishedAt' => Data::dateOut($this->publishedAt),
            'data' => Data::mapOut($this->data),
        ];
    }
}
