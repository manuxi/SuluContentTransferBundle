<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Security;

use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;

/**
 * What the current user may do with one article of a package: in which languages they may create it, edit it and
 * publish it, and whether they may write the images the article needs.
 */
final class ArticleImportAccess
{
    public const DENIED_ADD = 'import.denied_add';
    public const DENIED_EDIT = 'import.denied_edit';
    public const DENIED_LIVE = 'import.denied_live';
    public const DENIED_MEDIA = 'import.denied_media';

    /**
     * @param list<string> $add         languages (of those the plan imports) in which the user may create articles of its group
     * @param list<string> $edit        languages in which the user may edit articles of its group
     * @param list<string> $live        languages in which the user may publish articles of its group
     * @param bool         $mediaCreate the user may create the new images and new file versions the article needs
     * @param bool         $mediaTexts  the user may change the texts of the existing images the article uses
     */
    public function __construct(
        public readonly array $add,
        public readonly array $edit,
        public readonly array $live,
        public readonly bool $mediaCreate = true,
        public readonly bool $mediaTexts = true,
    ) {
    }

    /**
     * Why the article cannot be imported with this action, in these languages; null if the user may do it.
     *
     * @param list<string> $locales        languages that are written
     * @param list<string> $publishLocales languages that are published as well (empty without "keep status")
     */
    public function denial(string $action, array $locales, array $publishLocales, bool $overwriteMediaTexts): ?string
    {
        if (ArticleAction::SKIP === $action) {
            return null;
        }

        $needed = ArticleAction::UPDATE === $action ? $this->edit : $this->add;
        if ([] !== array_diff($locales, $needed)) {
            return ArticleAction::UPDATE === $action ? self::DENIED_EDIT : self::DENIED_ADD;
        }
        if ([] !== array_diff($publishLocales, $this->live)) {
            return self::DENIED_LIVE;
        }
        if (!$this->mediaCreate || ($overwriteMediaTexts && !$this->mediaTexts)) {
            return self::DENIED_MEDIA;
        }

        return null;
    }

    /**
     * @return array{add: list<string>, edit: list<string>, live: list<string>, mediaCreate: bool, mediaTexts: bool}
     */
    public function toArray(): array
    {
        return [
            'add' => $this->add,
            'edit' => $this->edit,
            'live' => $this->live,
            'mediaCreate' => $this->mediaCreate,
            'mediaTexts' => $this->mediaTexts,
        ];
    }
}
