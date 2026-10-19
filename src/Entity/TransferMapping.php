<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Entity;

/**
 * Remembers which thing on this system was created from which thing of another system, so a later import of the
 * same source finds it again and can tell whether it was changed here in the meantime.
 *
 * One row per (source system, kind, id on the source). "resourceKey" is "articles" (UUID) or "media" (number).
 * "importedAt" is the end of the import that wrote the row; a target changed after that point has been edited here.
 */
class TransferMapping
{
    public const TABLE = 'ct_transfer_mapping';

    private ?int $id = null;

    public function __construct(
        private string $sourceInstance,
        private string $resourceKey,
        private string $sourceId,
        private string $targetId,
        private \DateTimeImmutable $importedAt,
        private ?\DateTimeImmutable $sourceChanged = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceInstance(): string
    {
        return $this->sourceInstance;
    }

    public function getResourceKey(): string
    {
        return $this->resourceKey;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function setTargetId(string $targetId): static
    {
        $this->targetId = $targetId;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(\DateTimeImmutable $importedAt): static
    {
        $this->importedAt = $importedAt;

        return $this;
    }

    /**
     * Time of the last change on the source system that the import carried over (articles only).
     */
    public function getSourceChanged(): ?\DateTimeImmutable
    {
        return $this->sourceChanged;
    }

    public function setSourceChanged(?\DateTimeImmutable $sourceChanged): static
    {
        $this->sourceChanged = $sourceChanged;

        return $this;
    }
}
