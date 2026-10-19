<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

/**
 * What the analysis of one package knows while it plans the articles one after another.
 */
final class AnalysisContext
{
    /** @var array<string, true> addresses already given to an article of the package, per language */
    private array $claimed = [];

    /** @var array<string, true> */
    public readonly array $templateKeys;

    /**
     * @param list<string> $templateKeys article templates this system has
     */
    public function __construct(
        public readonly string $sourceInstance,
        public readonly bool $sameInstance,
        array $templateKeys,
        public readonly ReferenceResolver $resolver,
    ) {
        $this->templateKeys = array_fill_keys($templateKeys, true);
    }

    public function claim(string $locale, string $slug): void
    {
        $this->claimed[$locale."\0".$slug] = true;
    }

    public function isClaimed(string $locale, string $slug): bool
    {
        return isset($this->claimed[$locale."\0".$slug]);
    }

    public function hasTemplate(string $templateKey): bool
    {
        return isset($this->templateKeys[$templateKey]);
    }
}
