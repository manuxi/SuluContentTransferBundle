<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

/**
 * An article on this system, as far as the analysis needs it: when and by whom each version was last changed.
 */
final class TargetArticle
{
    /** @var list<TargetDimension> */
    public readonly array $dimensions;

    /**
     * @param list<TargetDimension> $dimensions the current draft and live version of every language
     */
    public function __construct(public readonly string $uuid, array $dimensions = [])
    {
        $this->dimensions = $dimensions;
    }

    public function dimension(string $locale, string $stage): ?TargetDimension
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->locale === $locale && $dimension->stage === $stage) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * The most recently changed version of any language and stage.
     */
    public function latest(): ?TargetDimension
    {
        $latest = null;
        foreach ($this->dimensions as $dimension) {
            if (null === $latest || $dimension->changed > $latest->changed) {
                $latest = $dimension;
            }
        }

        return $latest;
    }
}
