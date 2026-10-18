<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Package;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;
use Manuxi\SuluContentTransferBundle\Exception\PackageLimitExceededException;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Format\Package;

/**
 * Writes a Package as ZIP file. The same limits as for reading apply, so the writer never produces a file the reader
 * of the same configuration would refuse. The entries come in a fixed order and carry the creation time of the
 * manifest as modification time: the same Package gives the same bytes.
 */
class PackageWriter
{
    public function __construct(
        private readonly PackageLimits $limits,
        private readonly WorkDirectory $workDirectory,
    ) {
    }

    /**
     * @param array<int, string> $mediaFiles local file per media id, for every medium of the package
     */
    public function writeToFile(Package $package, string $targetFile, array $mediaFiles = []): void
    {
        $directory = \dirname($targetFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new ContentTransferException('The directory for the package file does not exist or is not writable.', 'package.not_writable');
        }

        $temporary = $targetFile.'.'.bin2hex(random_bytes(4)).'.part';
        try {
            $this->build($package, $temporary, $mediaFiles);
            if (!@rename($temporary, $targetFile)) {
                throw new ContentTransferException('The package file could not be written.', 'package.not_writable');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Writes the package to an open stream, e.g. "php://output" for a download.
     *
     * @param resource           $stream
     * @param array<int, string> $mediaFiles local file per media id, for every medium of the package
     */
    public function writeToStream(Package $package, $stream, array $mediaFiles = []): void
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('The target of the package must be a stream.');
        }

        $token = $this->workDirectory->create();
        try {
            $file = $this->workDirectory->path($token).\DIRECTORY_SEPARATOR.'package.zip';
            $this->build($package, $file, $mediaFiles);

            $source = fopen($file, 'rb');
            if (false === $source) {
                throw new ContentTransferException('The package file could not be read back.', 'package.not_writable');
            }
            try {
                if (false === stream_copy_to_stream($source, $stream)) {
                    throw new ContentTransferException('The package could not be written to the stream.', 'package.not_writable');
                }
            } finally {
                fclose($source);
            }
        } finally {
            $this->workDirectory->remove($token);
        }
    }

    /**
     * @param array<int, string> $mediaFiles
     */
    private function build(Package $package, string $file, array $mediaFiles): void
    {
        $this->assertMediaFiles($package, $mediaFiles);

        // path in the package => content; the manifest comes first, the rest in the order of the paths
        $others = [];
        foreach ($package->articles as $article) {
            $others[PackagePath::article($article->uuid)] = Json::encode($article->toArray());
        }
        foreach ($package->media as $entry) {
            $others[$entry->metaPath()] = Json::encode($entry->meta->toArray());
        }
        ksort($others, \SORT_STRING);
        $json = [PackagePath::MANIFEST => Json::encode($package->manifest->toArray())] + $others;

        $fileCount = \count($json) + \count($package->media);
        if ($fileCount > $this->limits->maxFiles) {
            throw PackageLimitExceededException::files($this->limits->maxFiles);
        }

        $total = 0;
        foreach ($json as $path => $content) {
            if (\strlen($content) > PackageLimits::MAX_JSON_FILE_SIZE) {
                throw PackageLimitExceededException::size(PackageLimits::MAX_JSON_FILE_SIZE);
            }
            $total += \strlen($content);
        }
        foreach ($package->media as $entry) {
            $total += $entry->meta->size;
        }
        if ($total > $this->limits->maxPackageSize) {
            throw PackageLimitExceededException::size($this->limits->maxPackageSize);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($file, \ZipArchive::CREATE | \ZipArchive::EXCL);
        if (true !== $result) {
            throw new ContentTransferException('The package file could not be created.', 'package.not_writable');
        }

        $mtime = $package->manifest->createdAt->getTimestamp();
        $add = static function (string $path) use ($zip, $mtime): void {
            if (method_exists($zip, 'setMtimeName')) {
                $zip->setMtimeName($path, max($mtime, 315532800)); // a ZIP cannot date before 1980
            }
        };

        $closed = false;
        try {
            foreach ($json as $path => $content) {
                if (!$zip->addFromString($path, $content)) {
                    throw new ContentTransferException('The package file could not be written.', 'package.not_writable');
                }
                $add($path);
            }
            foreach ($package->media as $entry) {
                $path = $entry->filePath();
                if (!$zip->addFile($mediaFiles[$entry->id], $path)) {
                    throw new ContentTransferException('The package file could not be written.', 'package.not_writable');
                }
                $add($path);
            }

            $closed = $zip->close();
        } finally {
            if (!$closed) {
                // close() failed or an exception interrupted us: discard the half written archive
                @$zip->unchangeAll();
                @$zip->close();
            }
        }
        if (!$closed) {
            throw new ContentTransferException('The package file could not be written.', 'package.not_writable');
        }

        clearstatcache(true, $file);
        $written = filesize($file);
        if (false !== $written && $written > $this->limits->maxPackageSize) {
            @unlink($file);
            throw PackageLimitExceededException::size($this->limits->maxPackageSize);
        }
    }

    /**
     * Every medium needs its file, the file must be what the meta data promises (size and SHA-256).
     *
     * @param array<int, string> $mediaFiles
     */
    private function assertMediaFiles(Package $package, array $mediaFiles): void
    {
        foreach ($package->media as $entry) {
            $file = $mediaFiles[$entry->id] ?? null;
            if (null === $file || !is_file($file) || !is_readable($file)) {
                throw new InvalidPackageException(\sprintf('The file of medium %d is missing.', $entry->id));
            }
            if (filesize($file) !== $entry->meta->size) {
                throw new InvalidPackageException(\sprintf('The file of medium %d does not have the size given in its meta data.', $entry->id), 'package.integrity');
            }
            if (hash_file('sha256', $file) !== $entry->meta->sha256) {
                throw new InvalidPackageException(\sprintf('The file of medium %d does not match the SHA-256 hash given in its meta data.', $entry->id), 'package.integrity');
            }
        }

        $unknown = array_diff(array_keys($mediaFiles), array_keys($package->media));
        if ([] !== $unknown) {
            throw new InvalidPackageException('Files were given for media that are not part of the package.');
        }
    }
}
