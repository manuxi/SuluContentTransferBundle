<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;
use Manuxi\SuluContentTransferBundle\Package\PackagePath;

/**
 * articles/<uuid>.json: one article with all exported languages and the places that reference other resources.
 */
final class ArticleEntry
{
    /** @var array<string, ArticleDimension> */
    public readonly array $dimensions;

    /** @var list<ArticleReference> */
    public readonly array $references;

    /**
     * @param \DateTimeImmutable           $changed    last change on the source system
     * @param array<int, ArticleDimension> $dimensions one per language
     * @param list<ArticleReference>       $references
     */
    public function __construct(
        public readonly string $uuid,
        public readonly \DateTimeImmutable $changed,
        array $dimensions,
        array $references = [],
    ) {
        PackagePath::article($uuid);

        $byLocale = [];
        foreach ($dimensions as $dimension) {
            if (isset($byLocale[$dimension->locale])) {
                throw new InvalidPackageException(\sprintf('Invalid package: article "%s" contains the language "%s" twice.', $uuid, $dimension->locale));
            }
            $byLocale[$dimension->locale] = $dimension;
        }
        ksort($byLocale, \SORT_STRING);
        $this->dimensions = $byLocale;

        usort($references, ArticleReference::compare(...));
        $this->references = $references;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'article';

        $dimensions = [];
        foreach (Data::map($data, 'dimensions', $path) as $locale => $dimension) {
            if (!\is_array($dimension)) {
                throw Data::error($path.'.dimensions', (string) $locale, 'an object');
            }
            $dimensions[] = ArticleDimension::fromArray((string) $locale, $dimension);
        }

        $references = [];
        foreach (Data::list($data, 'references', $path) as $index => $reference) {
            if (!\is_array($reference)) {
                throw Data::error($path.'.references', (string) $index, 'an object');
            }
            $references[] = ArticleReference::fromArray($reference);
        }

        return new self(
            Data::string($data, 'uuid', $path),
            Data::dateTime($data, 'changed', $path),
            $dimensions,
            $references,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'changed' => Data::dateOut($this->changed),
            'dimensions' => Data::mapOut(array_map(static fn (ArticleDimension $dimension): array => $dimension->toArray(), $this->dimensions)),
            'references' => array_map(static fn (ArticleReference $reference): array => $reference->toArray(), $this->references),
        ];
    }
}
