<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Report;

/**
 * The result of an import run, for the console and the admin.
 */
final class ImportReport
{
    /**
     * @param list<ArticleResult>                                            $articles
     * @param list<MediaResult>                                              $media
     * @param list<array{id: int, key: ?string, name: string, path: string}> $categories categories the run created
     * @param list<array{id: int, name: string}>                             $tags       tags the run created
     * @param list<string>                                                   $errors     problems that are not tied to one article
     */
    public function __construct(
        public readonly string $token,
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $finishedAt,
        public readonly array $articles,
        public readonly array $media,
        public readonly array $categories,
        public readonly array $tags,
        public readonly array $errors = [],
    ) {
    }

    public function hasFailures(): bool
    {
        foreach ($this->articles as $article) {
            if ($article->failed()) {
                return true;
            }
        }
        foreach ($this->media as $medium) {
            if (MediaResult::FAILED === $medium->outcome) {
                return true;
            }
        }

        return [] !== $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'startedAt' => $this->startedAt->format(\DATE_ATOM),
            'finishedAt' => $this->finishedAt->format(\DATE_ATOM),
            'articles' => array_map(static fn (ArticleResult $article): array => $article->toArray(), $this->articles),
            'media' => array_map(static fn (MediaResult $medium): array => $medium->toArray(), $this->media),
            'categories' => $this->categories,
            'tags' => $this->tags,
            'errors' => $this->errors,
        ];
    }
}
