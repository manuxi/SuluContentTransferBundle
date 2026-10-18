<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;

final class ExportRequest
{
    public const STAGE_LIVE = 'live';
    public const STAGE_DRAFT = 'draft';

    /**
     * @param list<string> $uuids   articles to export
     * @param list<string> $locales languages to export; empty means every language an article has
     * @param string       $stage   "live" exports the published version of a language (the draft if it is not published), "draft" the current draft
     */
    public function __construct(
        public readonly array $uuids,
        public readonly array $locales = [],
        public readonly string $stage = self::STAGE_LIVE,
        public readonly bool $includeMediaFiles = true,
    ) {
        if ([] === $uuids) {
            throw new ContentTransferException('No article was selected for the export.', 'export.nothing_selected');
        }

        if (!\in_array($stage, [self::STAGE_LIVE, self::STAGE_DRAFT], true)) {
            throw new ContentTransferException(\sprintf('Unknown version "%s", expected "live" or "draft".', $stage), 'export.unknown_stage');
        }
    }
}
