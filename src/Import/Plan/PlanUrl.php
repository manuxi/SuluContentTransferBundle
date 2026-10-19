<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

use Manuxi\SuluContentTransferBundle\Format\Data;

/**
 * The address an article gets in one language on this system.
 */
final class PlanUrl
{
    /**
     * @param ?string $requested  address as the package asks for it, built from the page found on this system and the
     *                            suffix (null: the article has no address in this language)
     * @param ?string $proposed   address that is free (same as "requested" if nothing else uses it)
     * @param ?string $takenBy    who uses "requested" on this system, e.g. "articles:<uuid>" or "pages:<uuid>"
     * @param ?string $parentPage UUID of the page on this system under which the address lives (page based addresses)
     */
    public function __construct(
        public readonly ?string $requested,
        public readonly ?string $proposed,
        public readonly ?string $takenBy = null,
        public readonly ?string $parentPage = null,
    ) {
    }

    public function isTaken(): bool
    {
        return null !== $this->requested && $this->requested !== $this->proposed;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::nullableString($data, 'requested', 'plan.url'),
            Data::nullableString($data, 'proposed', 'plan.url'),
            Data::nullableString($data, 'takenBy', 'plan.url'),
            Data::nullableString($data, 'parentPage', 'plan.url'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested' => $this->requested,
            'proposed' => $this->proposed,
            'takenBy' => $this->takenBy,
            'parentPage' => $this->parentPage,
        ];
    }
}
