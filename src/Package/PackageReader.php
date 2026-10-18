<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Package;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;
use Manuxi\SuluContentTransferBundle\Exception\PackageLimitExceededException;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Format\Manifest;
use Manuxi\SuluContentTransferBundle\Format\MediaEntry;
use Manuxi\SuluContentTransferBundle\Format\MediaMeta;
use Manuxi\SuluContentTransferBundle\Format\Package;

/**
 * Reads a package file. A package is untrusted input, so before anything is unpacked every entry is checked:
 *
 * - only the paths of PackagePath (manifest, articles by UUID, media by number) and nothing else, which excludes
 *   absolute paths, "..", backslashes and foreign files;
 * - no encrypted entries, no symbolic links, no duplicates;
 * - number of files and (declared and actually unpacked) size within the configured limits;
 * - the format version: a package of a newer version is refused with a clear message;
 * - afterwards: every file of a medium matches its size and SHA-256, the parts belong together.
 *
 * The files are unpacked into a new random subdirectory of the work directory, never anywhere else.
 */
class PackageReader
{
    private const CHUNK_SIZE = 65536;

    public function __construct(
        private readonly PackageLimits $limits,
        private readonly WorkDirectory $workDirectory,
    ) {
    }

    public function read(string $zipFile): ExtractedPackage
    {
        if (!is_file($zipFile) || !is_readable($zipFile)) {
            throw new InvalidPackageException('The package file does not exist or cannot be read.');
        }
        clearstatcache(true, $zipFile);
        if ((int) filesize($zipFile) > $this->limits->maxPackageSize) {
            throw PackageLimitExceededException::size($this->limits->maxPackageSize);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipFile, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS);
        if (true !== $result) {
            throw new InvalidPackageException('The file is not a readable ZIP package.');
        }

        try {
            if ($zip->numFiles > $this->limits->maxFiles) {
                throw PackageLimitExceededException::files($this->limits->maxFiles);
            }

            // the version comes first: a package of a newer version may have a layout this version cannot check
            $manifestData = $this->readManifestData($zip);
            Manifest::assertSupportedVersion($manifestData);

            $entries = $this->inspect($zip);
            $manifest = Manifest::fromArray($manifestData);

            $token = $this->workDirectory->create();
            try {
                $directory = $this->workDirectory->path($token);
                $this->extract($zip, $entries, $directory);
                $package = $this->load($manifest, $entries, $directory);

                return new ExtractedPackage($token, $directory, $package);
            } catch (\Throwable $exception) {
                $this->workDirectory->remove($token);

                throw $exception;
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readManifestData(\ZipArchive $zip): array
    {
        $stat = $zip->statName(PackagePath::MANIFEST);
        if (false === $stat) {
            throw new InvalidPackageException('The package contains no manifest.json.');
        }
        if ($stat['size'] > PackageLimits::MAX_JSON_FILE_SIZE) {
            throw PackageLimitExceededException::size(PackageLimits::MAX_JSON_FILE_SIZE);
        }

        $content = $zip->getFromName(PackagePath::MANIFEST, PackageLimits::MAX_JSON_FILE_SIZE + 1);
        if (false === $content || \strlen($content) > PackageLimits::MAX_JSON_FILE_SIZE) {
            throw new InvalidPackageException('The manifest.json cannot be read.');
        }

        return Json::decode($content, 'manifest.json');
    }

    /**
     * Checks all entries without unpacking anything.
     *
     * @return list<array{index: int, name: string, size: int, kind: string, id: string|null, file: string|null}>
     */
    private function inspect(\ZipArchive $zip): array
    {
        $entries = [];
        $seen = [];
        $total = 0;
        $hasManifest = false;

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
            if (false === $stat) {
                throw new InvalidPackageException('The package contains an unreadable entry.');
            }

            $name = (string) $stat['name'];
            $kind = PackagePath::classify($name);
            if (null === $kind) {
                throw new InvalidPackageException(\sprintf('The package contains an unexpected entry %s.', PackagePath::describe($name)), 'package.unexpected_entry');
            }

            $key = strtolower($name);
            if (isset($seen[$key])) {
                throw new InvalidPackageException(\sprintf('The package contains the entry %s twice.', PackagePath::describe($name)), 'package.unexpected_entry');
            }
            $seen[$key] = true;

            if (PackagePath::KIND_DIRECTORY === $kind['kind']) {
                continue;
            }

            if (\ZipArchive::EM_NONE !== $stat['encryption_method']) {
                throw new InvalidPackageException(\sprintf('The entry %s is encrypted.', PackagePath::describe($name)), 'package.unexpected_entry');
            }
            if ($this->isSymbolicLink($zip, $index)) {
                throw new InvalidPackageException(\sprintf('The entry %s is a symbolic link.', PackagePath::describe($name)), 'package.unexpected_entry');
            }

            $size = (int) $stat['size'];
            $isJson = PackagePath::KIND_MEDIA_FILE !== $kind['kind'];
            if ($isJson && $size > PackageLimits::MAX_JSON_FILE_SIZE) {
                throw PackageLimitExceededException::size(PackageLimits::MAX_JSON_FILE_SIZE);
            }
            $total += $size;
            if ($total > $this->limits->maxPackageSize) {
                throw PackageLimitExceededException::size($this->limits->maxPackageSize);
            }

            $hasManifest = $hasManifest || PackagePath::KIND_MANIFEST === $kind['kind'];
            $entries[] = ['index' => $index, 'name' => $name, 'size' => $size] + $kind;
        }

        if (!$hasManifest) {
            throw new InvalidPackageException('The package contains no manifest.json.');
        }

        return $entries;
    }

    private function isSymbolicLink(\ZipArchive $zip, int $index): bool
    {
        $operatingSystem = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return false;
        }

        return \ZipArchive::OPSYS_UNIX === $operatingSystem && 0120000 === (($attributes >> 16) & 0170000);
    }

    /**
     * @param list<array{index: int, name: string, size: int, kind: string, id: string|null, file: string|null}> $entries
     */
    private function extract(\ZipArchive $zip, array $entries, string $directory): void
    {
        $root = realpath($directory);
        if (false === $root) {
            throw new InvalidPackageException('The work directory of the package does not exist.');
        }

        $total = 0;
        foreach ($entries as $entry) {
            $target = $root.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $entry['name']);
            $parent = \dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new InvalidPackageException('The package could not be unpacked.');
            }

            // the checked names cannot leave the directory; this is the second line of defence
            $realParent = realpath($parent);
            if (false === $realParent || ($realParent !== $root && !str_starts_with($realParent, $root.\DIRECTORY_SEPARATOR))) {
                throw new InvalidPackageException(\sprintf('The entry %s would be unpacked outside of the work directory.', PackagePath::describe($entry['name'])), 'package.unexpected_entry');
            }

            $source = $zip->getStream($entry['name']);
            if (false === $source) {
                throw new InvalidPackageException(\sprintf('The entry %s cannot be read.', PackagePath::describe($entry['name'])));
            }
            $destination = fopen($target, 'xb');
            if (false === $destination) {
                fclose($source);
                throw new InvalidPackageException('The package could not be unpacked.');
            }

            try {
                $written = 0;
                while (!feof($source)) {
                    $chunk = fread($source, self::CHUNK_SIZE);
                    if (false === $chunk) {
                        throw new InvalidPackageException(\sprintf('The entry %s cannot be read.', PackagePath::describe($entry['name'])));
                    }
                    $written += \strlen($chunk);
                    $total += \strlen($chunk);
                    // the sizes in the headers are only claims: count what is really unpacked
                    if ($written > $entry['size'] || $total > $this->limits->maxPackageSize) {
                        throw PackageLimitExceededException::size($this->limits->maxPackageSize);
                    }
                    if (false === fwrite($destination, $chunk)) {
                        throw new InvalidPackageException('The package could not be unpacked.');
                    }
                }
            } finally {
                fclose($source);
                fclose($destination);
            }

            if ($written !== $entry['size']) {
                throw new InvalidPackageException(\sprintf('The entry %s is damaged.', PackagePath::describe($entry['name'])), 'package.integrity');
            }
        }
    }

    /**
     * @param list<array{index: int, name: string, size: int, kind: string, id: string|null, file: string|null}> $entries
     */
    private function load(Manifest $manifest, array $entries, string $directory): Package
    {
        $articles = [];
        $metas = [];
        $files = [];

        foreach ($entries as $entry) {
            $path = $directory.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $entry['name']);

            switch ($entry['kind']) {
                case PackagePath::KIND_ARTICLE:
                    $article = ArticleEntry::fromArray(Json::decode((string) file_get_contents($path), $entry['name']));
                    if ($article->uuid !== $entry['id']) {
                        throw new InvalidPackageException(\sprintf('The article in %s has a different id than its file name.', PackagePath::describe($entry['name'])));
                    }
                    $articles[] = $article;
                    break;
                case PackagePath::KIND_MEDIA_META:
                    $metas[(int) $entry['id']] = MediaMeta::fromArray(Json::decode((string) file_get_contents($path), $entry['name']));
                    break;
                case PackagePath::KIND_MEDIA_FILE:
                    if (isset($files[(int) $entry['id']])) {
                        throw new InvalidPackageException(\sprintf('The medium %s contains more than one file.', $entry['id']));
                    }
                    $files[(int) $entry['id']] = (string) $entry['file'];
                    break;
            }
        }

        $media = [];
        foreach ($metas as $id => $meta) {
            if (!isset($files[$id])) {
                throw new InvalidPackageException(\sprintf('The medium %d has meta data but no file.', $id));
            }
            $entry = new MediaEntry($id, $meta, $files[$id]);
            $file = $directory.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $entry->filePath());
            if (filesize($file) !== $meta->size || hash_file('sha256', $file) !== $meta->sha256) {
                throw new InvalidPackageException(\sprintf('The file of medium %d does not match the size and SHA-256 hash in its meta data.', $id), 'package.integrity');
            }
            $media[] = $entry;
        }
        foreach (array_keys($files) as $id) {
            if (!isset($metas[$id])) {
                throw new InvalidPackageException(\sprintf('The medium %d has a file but no meta data.', $id));
            }
        }

        return new Package($manifest, $articles, $media);
    }
}
