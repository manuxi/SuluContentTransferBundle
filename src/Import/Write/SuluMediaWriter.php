<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

use Doctrine\ORM\EntityManagerInterface;
use Manuxi\SuluContentTransferBundle\Format\MediaMeta;
use Sulu\Bundle\MediaBundle\Media\Exception\FormatNotFoundException;
use Sulu\Bundle\MediaBundle\Media\Exception\FormatOptionsMissingParameterException;
use Sulu\Bundle\MediaBundle\Media\FormatOptions\FormatOptionsManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Creates and updates images through Sulu's media manager, so the file goes to the configured storage and events,
 * search and activity log follow. New images get the origin "import".
 */
class SuluMediaWriter implements MediaWriter
{
    public const ORIGIN = 'import';

    private const TEXT_FIELDS = ['title', 'description', 'copyright', 'credits'];

    public function __construct(
        private readonly MediaManagerInterface $mediaManager,
        private readonly FormatOptionsManagerInterface $formatOptions,
        private readonly SystemCollectionManagerInterface $systemCollections,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportUserContext $user,
    ) {
    }

    public function systemCollectionId(string $key): int
    {
        return (int) $this->systemCollections->getSystemCollection($key);
    }

    public function create(string $file, MediaMeta $meta, int $collectionId, array $tagIds, array $categoryIds, string $fallbackLocale): MediaWritten
    {
        $locales = array_keys($meta->meta);
        $first = (string) ($locales[0] ?? $fallbackLocale);

        $data = [
            'locale' => $first,
            'collection' => $collectionId,
            'tags' => $tagIds,
            'categories' => $categoryIds,
            'origin' => self::ORIGIN,
        ] + self::texts($meta, $first);
        if (null !== $meta->focusPoint) {
            $data['focusPointX'] = $meta->focusPoint['x'];
            $data['focusPointY'] = $meta->focusPoint['y'];
        }

        $media = $this->mediaManager->save($this->upload($file, $meta), $data, $this->user->userId());
        $id = (int) $media->getId();

        foreach ($locales as $locale) {
            if ((string) $locale === $first) {
                continue;
            }
            $this->mediaManager->save(null, ['id' => $id, 'locale' => (string) $locale] + self::texts($meta, (string) $locale), $this->user->userId());
        }

        $warnings = [];
        foreach ($meta->formatOptions as $format => $crop) {
            try {
                $this->formatOptions->save($id, (string) $format, $crop);
            } catch (FormatNotFoundException) {
                $warnings[] = \sprintf('The crop for the image format "%s" was not taken over: this system has no such format.', $format);
            } catch (FormatOptionsMissingParameterException) {
                $warnings[] = \sprintf('The crop for the image format "%s" was not taken over: it is incomplete.', $format);
            }
        }
        $this->entityManager->flush();

        return new MediaWritten($id, $warnings);
    }

    public function addVersion(int $mediaId, string $file, MediaMeta $meta, string $fallbackLocale): MediaWritten
    {
        $locales = array_keys($meta->meta);
        $this->mediaManager->save($this->upload($file, $meta), ['id' => $mediaId, 'locale' => (string) ($locales[0] ?? $fallbackLocale)], $this->user->userId());
        $this->entityManager->flush();

        return new MediaWritten($mediaId);
    }

    public function updateTexts(int $mediaId, MediaMeta $meta): void
    {
        foreach (array_keys($meta->meta) as $locale) {
            $this->mediaManager->save(null, ['id' => $mediaId, 'locale' => (string) $locale] + self::texts($meta, (string) $locale), $this->user->userId());
        }
        $this->entityManager->flush();
    }

    private function upload(string $file, MediaMeta $meta): UploadedFile
    {
        // "test" mode: the file does not come from an HTTP upload but from the unpacked package
        return new UploadedFile($file, $meta->filename, $meta->mimeType, null, true);
    }

    /**
     * @return array<string, string>
     */
    private static function texts(MediaMeta $meta, string $locale): array
    {
        $texts = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = $meta->meta[$locale][$field] ?? null;
            if (null !== $value && '' !== $value) {
                $texts[$field] = $value;
            }
        }

        return $texts;
    }
}
