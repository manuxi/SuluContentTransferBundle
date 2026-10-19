<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

use Manuxi\SuluContentTransferBundle\Format\Data;

/**
 * One language of an article of the package and whether this system takes it.
 */
final class PlanLocale
{
    /** The language is not configured in the main webspace of the article on this system. */
    public const REASON_LOCALE_UNKNOWN = 'locale_unknown';
    /** The template of the language does not exist on this system. */
    public const REASON_TEMPLATE_MISSING = 'template_missing';
    /** The page under which the address of the article lives is not there and cannot be found by its URL. */
    public const REASON_URL_PARENT_MISSING = 'url_parent_missing';

    /**
     * @param 'live'|'draft' $stage     version the package carries
     * @param bool           $published whether the source had the language published
     * @param ?string        $reason    why the language is left out ("import" is false), else null
     */
    public function __construct(
        public readonly string $locale,
        public readonly bool $import,
        public readonly string $stage,
        public readonly bool $published,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $stage = Data::string($data, 'stage', 'plan.locale');
        Data::assertSupportedStage($stage, 'plan.locale.stage');

        return new self(
            Data::string($data, 'locale', 'plan.locale'),
            Data::bool($data, 'import', 'plan.locale'),
            $stage,
            Data::bool($data, 'published', 'plan.locale'),
            Data::nullableString($data, 'reason', 'plan.locale'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'import' => $this->import,
            'stage' => $this->stage,
            'published' => $this->published,
            'reason' => $this->reason,
        ];
    }
}
