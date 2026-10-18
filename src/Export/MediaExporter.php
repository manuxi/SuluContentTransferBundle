<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Export;

use Doctrine\ORM\EntityManagerInterface;
use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Format\MediaEntry;
use Manuxi\SuluContentTransferBundle\Format\MediaMeta;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Bundle\MediaBundle\Entity\CollectionInterface;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;

/**
 * Reads the media an export refers to: the original file of the current version through Sulu's storage service
 * (never by guessing a path), its SHA-256 and the data of the medium that the importer restores (texts per language,
 * focus point, crops, tags, categories, properties, folder).
 */
class MediaExporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageInterface $storage,
    ) {
    }

    /**
     * @param list<int> $ids
     * @param string    $directory empty directory for the copies of the files (they are needed to compute the hash)
     */
    public function export(array $ids, string $directory, bool $keepFiles): MediaExport
    {
        $entries = [];
        $files = [];
        $rows = [];
        $missing = [];

        foreach (array_values(array_unique($ids)) as $id) {
            $media = $this->entityManager->getRepository(MediaInterface::class)->find($id);
            $fileVersion = $media instanceof MediaInterface ? self::currentFileVersion($media) : null;
            if (!$media instanceof MediaInterface || null === $fileVersion) {
                $missing[] = $id;

                continue;
            }

            $target = rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . 'media-' . $id;
            [$size, $sha256] = $this->copyOriginal($fileVersion, $target, $id);

            $packageName = PackageFileName::sanitize($fileVersion->getName(), $id);
            $meta = $this->buildMeta($media, $fileVersion, $size, $sha256);
            $entries[$id] = new MediaEntry($id, $meta, $packageName);
            $rows[] = ['id' => $id, 'filename' => $fileVersion->getName(), 'size' => $size, 'sha256' => $sha256];

            if ($keepFiles) {
                $files[$id] = $target;
            } else {
                @unlink($target);
            }
        }

        return new MediaExport($entries, $files, $rows, $missing);
    }

    private static function currentFileVersion(MediaInterface $media): ?FileVersion
    {
        $file = $media->getFiles()->first();
        if (false === $file) {
            return null;
        }

        return $file->getFileVersion($file->getVersion());
    }

    /**
     * @return array{0: int, 1: string} size in bytes and SHA-256 of the file as stored
     */
    private function copyOriginal(FileVersion $fileVersion, string $target, int $id): array
    {
        $source = $this->storage->load($fileVersion->getStorageOptions());
        $destination = @fopen($target, 'wb');
        if (false === $destination) {
            throw new ContentTransferException(\sprintf('A temporary copy of medium %d could not be written.', $id), 'export.temp_not_writable');
        }

        $context = hash_init('sha256');
        $size = 0;
        while (!feof($source)) {
            $chunk = fread($source, 1 << 20);
            if (false === $chunk) {
                break;
            }
            hash_update($context, $chunk);
            $size += \strlen($chunk);
            fwrite($destination, $chunk);
        }
        fclose($destination);
        if (\is_resource($source)) {
            fclose($source);
        }

        return [$size, hash_final($context)];
    }

    private function buildMeta(MediaInterface $media, FileVersion $fileVersion, int $size, string $sha256): MediaMeta
    {
        $texts = [];
        foreach ($fileVersion->getMeta() as $meta) {
            $texts[$meta->getLocale()] = [
                'title' => $meta->getTitle(),
                'description' => $meta->getDescription(),
                'copyright' => $meta->getCopyright(),
                'credits' => $meta->getCredits(),
            ];
        }

        $focusPoint = null;
        if (null !== $fileVersion->getFocusPointX() && null !== $fileVersion->getFocusPointY()) {
            $focusPoint = ['x' => $fileVersion->getFocusPointX(), 'y' => $fileVersion->getFocusPointY()];
        }

        $formatOptions = [];
        foreach ($fileVersion->getFormatOptions() as $options) {
            $formatOptions[$options->getFormatKey()] = [
                'cropX' => $options->getCropX(),
                'cropY' => $options->getCropY(),
                'cropWidth' => $options->getCropWidth(),
                'cropHeight' => $options->getCropHeight(),
            ];
        }

        $tags = [];
        foreach ($fileVersion->getTags() as $tag) {
            $tags[] = $tag->getName();
        }

        $categories = [];
        foreach ($fileVersion->getCategories() as $category) {
            $categories[] = ['key' => $category->getKey(), 'names' => self::categoryNames($category)];
        }

        $properties = $fileVersion->getProperties();

        return new MediaMeta(
            $fileVersion->getName(),
            $fileVersion->getMimeType() ?? 'application/octet-stream',
            $size,
            $sha256,
            $texts,
            $focusPoint,
            $formatOptions,
            $tags,
            $categories,
            \is_array($properties) ? $properties : [],
            self::collectionOf($media->getCollection()),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function categoryNames(CategoryInterface $category): array
    {
        $names = [];
        foreach ($category->getTranslations() as $translation) {
            $names[$translation->getLocale()] = $translation->getTranslation();
        }

        return $names;
    }

    /**
     * @return array{key: string|null, titlePath: list<array<string, string>>}
     */
    private static function collectionOf(CollectionInterface $collection): array
    {
        $path = [];
        for ($current = $collection; null !== $current; $current = $current->getParent()) {
            $titles = [];
            foreach ($current->getMeta() as $meta) {
                $titles[$meta->getLocale()] = $meta->getTitle();
            }
            array_unshift($path, $titles);
        }

        return ['key' => $collection->getKey(), 'titlePath' => $path];
    }
}
