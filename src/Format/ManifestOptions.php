<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

/**
 * What the exporter was asked for.
 */
final class ManifestOptions
{
    /** @var list<string> */
    public readonly array $locales;

    /**
     * @param list<string>   $locales
     * @param 'live'|'draft' $stage
     */
    public function __construct(
        array $locales,
        public readonly string $stage,
        public readonly bool $includeMediaFiles,
    ) {
        Data::assertSupportedStage($stage, 'options.stage');

        $locales = array_values(array_unique($locales));
        sort($locales, \SORT_STRING);
        $this->locales = $locales;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'options';

        return new self(
            Data::stringList($data, 'locales', $path),
            Data::string($data, 'stage', $path),
            Data::bool($data, 'includeMediaFiles', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'locales' => $this->locales,
            'stage' => $this->stage,
            'includeMediaFiles' => $this->includeMediaFiles,
        ];
    }
}
