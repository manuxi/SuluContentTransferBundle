<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

/**
 * The system that created a package.
 */
final class ManifestSource
{
    public function __construct(
        public readonly string $instanceId,
        public readonly string $label,
        public readonly string $suluVersion,
        public readonly string $bundleVersion,
        public readonly ?string $user = null,
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'source';

        return new self(
            Data::string($data, 'instanceId', $path),
            Data::string($data, 'label', $path),
            Data::string($data, 'suluVersion', $path),
            Data::string($data, 'bundleVersion', $path),
            Data::nullableString($data, 'user', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'instanceId' => $this->instanceId,
            'label' => $this->label,
            'suluVersion' => $this->suluVersion,
            'bundleVersion' => $this->bundleVersion,
            'user' => $this->user,
        ];
    }
}
