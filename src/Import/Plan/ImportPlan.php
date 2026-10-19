<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

use Manuxi\SuluContentTransferBundle\Format\Data;
use Manuxi\SuluContentTransferBundle\Format\ManifestSource;

/**
 * The result of the analysis of a package: what an import would do on this system. Nothing in it has been written.
 * It is stored as plan.json next to the unpacked package under the token and expires after 24 hours.
 */
final class ImportPlan
{
    public const FORMAT_VERSION = 1;
    public const LIFETIME_SECONDS = 86400;

    /** @var list<PlanArticle> */
    public readonly array $articles;

    /** @var list<PlanMedium> */
    public readonly array $media;

    /** @var list<PlanCategory> */
    public readonly array $categories;

    /** @var list<string> */
    public readonly array $tags;

    /** @var array<string, PlanResolution> */
    public readonly array $resolutions;

    /** @var list<Notice> */
    public readonly array $notices;

    /**
     * @param array<string, mixed> $options      the options of the package (locales, stage, includeMediaFiles)
     * @param list<PlanArticle>    $articles
     * @param list<PlanMedium>     $media
     * @param list<PlanCategory>   $categories   categories that are created
     * @param list<string>         $tags         names of tags that are created
     * @param list<PlanResolution> $resolutions
     * @param list<Notice>         $notices
     * @param bool                 $sameInstance the package comes from this very system
     */
    public function __construct(
        public readonly string $token,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ManifestSource $source,
        public readonly \DateTimeImmutable $packageCreatedAt,
        public readonly array $options,
        public readonly bool $sameInstance,
        public readonly string $targetInstanceId,
        public readonly string $targetLabel,
        array $articles,
        array $media,
        array $categories,
        array $tags,
        array $resolutions,
        array $notices,
    ) {
        $this->articles = $articles;
        $this->media = $media;
        $this->categories = $categories;

        $tags = array_values(array_unique($tags));
        sort($tags, \SORT_STRING);
        $this->tags = $tags;

        $byKey = [];
        foreach ($resolutions as $resolution) {
            $byKey[PlanResolution::key($resolution->kind, $resolution->sourceId)] = $resolution;
        }
        ksort($byKey, \SORT_STRING);
        $this->resolutions = $byKey;

        $this->notices = $notices;
    }

    public function resolution(string $kind, int|string $sourceId): ?PlanResolution
    {
        return $this->resolutions[PlanResolution::key($kind, $sourceId)] ?? null;
    }

    public function article(string $uuid): ?PlanArticle
    {
        foreach ($this->articles as $article) {
            if ($article->uuid === $uuid) {
                return $article;
            }
        }

        return null;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) >= $this->expiresAt;
    }

    public function hasErrors(): bool
    {
        foreach ($this->notices as $notice) {
            if (Notice::ERROR === $notice->level) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'plan';

        if (self::FORMAT_VERSION !== ($data['planVersion'] ?? null)) {
            throw Data::error($path, 'planVersion', 'a plan of this version');
        }

        $articles = [];
        foreach (Data::list($data, 'articles', $path) as $article) {
            $articles[] = PlanArticle::fromArray(\is_array($article) ? $article : throw Data::error($path, 'articles', 'a list of objects'));
        }
        $media = [];
        foreach (Data::list($data, 'media', $path) as $medium) {
            $media[] = PlanMedium::fromArray(\is_array($medium) ? $medium : throw Data::error($path, 'media', 'a list of objects'));
        }
        $categories = [];
        foreach (Data::list($data, 'categories', $path) as $category) {
            $categories[] = PlanCategory::fromArray(\is_array($category) ? $category : throw Data::error($path, 'categories', 'a list of objects'));
        }
        $resolutions = [];
        foreach (Data::list($data, 'resolutions', $path) as $resolution) {
            $resolutions[] = PlanResolution::fromArray(\is_array($resolution) ? $resolution : throw Data::error($path, 'resolutions', 'a list of objects'));
        }
        $notices = [];
        foreach (Data::list($data, 'notices', $path) as $notice) {
            $notices[] = Notice::fromArray(\is_array($notice) ? $notice : throw Data::error($path, 'notices', 'a list of objects'));
        }

        return new self(
            Data::string($data, 'token', $path),
            Data::dateTime($data, 'createdAt', $path),
            Data::dateTime($data, 'expiresAt', $path),
            ManifestSource::fromArray(Data::map($data, 'source', $path)),
            Data::dateTime($data, 'packageCreatedAt', $path),
            Data::map($data, 'options', $path),
            Data::bool($data, 'sameInstance', $path),
            Data::string($data, 'targetInstanceId', $path),
            Data::string($data, 'targetLabel', $path),
            $articles,
            $media,
            $categories,
            Data::stringList($data, 'tags', $path),
            $resolutions,
            $notices,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'planVersion' => self::FORMAT_VERSION,
            'token' => $this->token,
            'createdAt' => Data::dateOut($this->createdAt),
            'expiresAt' => Data::dateOut($this->expiresAt),
            'source' => $this->source->toArray(),
            'packageCreatedAt' => Data::dateOut($this->packageCreatedAt),
            'options' => Data::mapOut($this->options),
            'sameInstance' => $this->sameInstance,
            'targetInstanceId' => $this->targetInstanceId,
            'targetLabel' => $this->targetLabel,
            'articles' => array_map(static fn (PlanArticle $article): array => $article->toArray(), $this->articles),
            'media' => array_map(static fn (PlanMedium $medium): array => $medium->toArray(), $this->media),
            'categories' => array_map(static fn (PlanCategory $category): array => $category->toArray(), $this->categories),
            'tags' => $this->tags,
            'resolutions' => array_values(array_map(static fn (PlanResolution $resolution): array => $resolution->toArray(), $this->resolutions)),
            'notices' => array_map(static fn (Notice $notice): array => $notice->toArray(), $this->notices),
        ];
    }
}
