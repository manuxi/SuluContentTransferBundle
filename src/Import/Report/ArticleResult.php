<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Report;

use Manuxi\SuluContentTransferBundle\Import\Rewrite\RemovedReference;

/**
 * What the import did with one article of the package.
 */
final class ArticleResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const COPIED = 'copied';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /**
     * @param string                                                              $sourceUuid     UUID in the package
     * @param self::CREATED|self::UPDATED|self::COPIED|self::SKIPPED|self::FAILED $status
     * @param ?string                                                             $targetUuid     UUID on this system (null if nothing was written)
     * @param list<string>                                                        $locales        languages that were written
     * @param array<string, string>                                               $skippedLocales language => reason the plan gave
     * @param list<string>                                                        $published      languages that were published
     * @param array<string, array{from: string, to: string}>                      $renamedUrls    addresses that were taken and got a number
     * @param list<RemovedReference>                                              $removed        references that were removed
     * @param array<string, string>                                               $adminLinks     language => path into the admin
     * @param ?string                                                             $message        why the article was skipped, or what failed
     */
    public function __construct(
        public readonly string $sourceUuid,
        public readonly string $title,
        public readonly string $action,
        public readonly string $status,
        public readonly ?string $targetUuid = null,
        public readonly array $locales = [],
        public readonly array $skippedLocales = [],
        public readonly array $published = [],
        public readonly array $renamedUrls = [],
        public readonly array $removed = [],
        public readonly array $adminLinks = [],
        public readonly ?string $message = null,
    ) {
    }

    public function failed(): bool
    {
        return self::FAILED === $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceUuid' => $this->sourceUuid,
            'title' => $this->title,
            'action' => $this->action,
            'status' => $this->status,
            'targetUuid' => $this->targetUuid,
            'locales' => $this->locales,
            'skippedLocales' => $this->skippedLocales,
            'published' => $this->published,
            'renamedUrls' => $this->renamedUrls,
            'removedReferences' => array_map(static fn (RemovedReference $reference): array => $reference->toArray(), $this->removed),
            'adminLinks' => $this->adminLinks,
            'message' => $this->message,
        ];
    }
}
