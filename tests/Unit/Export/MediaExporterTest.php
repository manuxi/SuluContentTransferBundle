<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Export;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Manuxi\SuluContentTransferBundle\Export\MediaExporter;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use Sulu\Bundle\CategoryBundle\Entity\Category;
use Sulu\Bundle\CategoryBundle\Entity\CategoryTranslation;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionMeta;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\FileVersionMeta;
use Sulu\Bundle\MediaBundle\Entity\FormatOptions;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Sulu\Bundle\TagBundle\Entity\Tag;

class MediaExporterTest extends TemporaryDirectoryTestCase
{
    /** @var array<int, Media> */
    private array $media = [];
    /** @var array<string, string> */
    private array $stored = [];
    private ?object $storageDouble = null;

    private function storage(): StorageInterface
    {
        return $this->storageDouble = new class($this->stored) implements StorageInterface {
            /** @var list<array<string, string|null>> */
            public array $loaded = [];

            /**
             * @param array<string, string> $stored
             */
            public function __construct(private readonly array $stored)
            {
            }

            public function save(string $tempPath, string $fileName, array $storageOptions = []): array
            {
                return [];
            }

            public function load(array $storageOptions)
            {
                $this->loaded[] = $storageOptions;
                $stream = fopen('php://memory', 'w+b');
                fwrite($stream, $this->stored[$storageOptions['fileName']]);
                rewind($stream);

                return $stream;
            }

            public function getPath(array $storageOptions): string
            {
                return '';
            }

            public function getType(array $storageOptions): string
            {
                return self::TYPE_LOCAL;
            }

            public function move(array $sourceStorageOptions, array $targetStorageOptions): array
            {
                return [];
            }

            public function remove(array $storageOptions): void
            {
            }
        };
    }

    private function exporter(): MediaExporter
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturnCallback(fn (int $id): ?Media => $this->media[$id] ?? null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(MediaInterface::class)->willReturn($repository);

        return new MediaExporter($entityManager, $this->storage());
    }

    private function collection(): Collection
    {
        $root = new Collection();
        $root->setKey(null);
        $rootMeta = (new CollectionMeta())->setTitle('Tutorials')->setLocale('en');
        $rootMeta2 = (new CollectionMeta())->setTitle('Anleitungen')->setLocale('de');
        $root->addMeta($rootMeta)->addMeta($rootMeta2);

        $child = new Collection();
        $child->setKey('screenshots');
        $child->setParent($root);
        $child->addMeta((new CollectionMeta())->setTitle('Screenshots')->setLocale('en'));
        $child->addMeta((new CollectionMeta())->setTitle('Bildschirmfotos')->setLocale('de'));

        return $child;
    }

    private function medium(int $id, string $name, string $content, int $version = 2): Media
    {
        $this->stored['segment-' . $id . '-' . $version] = $content;
        $this->stored['old-' . $id] = 'previous version of the file';

        $file = new File();
        $file->setVersion($version);

        $old = (new FileVersion())->setVersion(1)->setName('old-' . $name)->setSize(strlen('previous version of the file'))->setStorageOptions(['fileName' => 'old-' . $id]);
        $current = (new FileVersion())->setVersion($version)->setName($name)->setSize(strlen($content))->setMimeType('image/jpeg')->setStorageOptions(['fileName' => 'segment-' . $id . '-' . $version]);
        $file->addFileVersion($old)->addFileVersion($current);

        $media = new Media();
        $media->addFile($file);
        $media->setCollection($this->collection());
        $this->media[$id] = $media;

        return $media;
    }

    private function currentVersion(Media $media): FileVersion
    {
        $file = $media->getFiles()->first();
        $this->assertInstanceOf(File::class, $file);
        $version = $file->getFileVersion($file->getVersion());
        $this->assertInstanceOf(FileVersion::class, $version);

        return $version;
    }

    public function testCopiesTheOriginalOfTheCurrentVersionThroughTheStorageAndHashesIt(): void
    {
        $this->medium(7, 'Screenshot (1).jpg', 'the bytes of the image');
        mkdir($this->workDir, 0700, true);

        $result = $this->exporter()->export([7], $this->workDir, true);

        $this->assertSame([['fileName' => 'segment-7-2']], $this->storageDouble?->loaded, 'the current version was read, through the storage');
        $this->assertSame([7], array_keys($result->files));
        $this->assertSame('the bytes of the image', file_get_contents($result->files[7]));
        $this->assertSame([['id' => 7, 'filename' => 'Screenshot (1).jpg', 'size' => 22, 'sha256' => hash('sha256', 'the bytes of the image')]], $result->rows);
        $entry = $result->entries[7];
        $this->assertSame('Screenshot (1).jpg', $entry->fileName);
        $this->assertSame(hash('sha256', 'the bytes of the image'), $entry->meta->sha256);
        $this->assertSame(22, $entry->meta->size);
        $this->assertSame('image/jpeg', $entry->meta->mimeType);
        $this->assertSame([], $result->missing);
    }

    public function testWithoutFilesOnlyTheHashRemains(): void
    {
        $this->medium(7, 'a.jpg', 'abc');
        mkdir($this->workDir, 0700, true);

        $result = $this->exporter()->export([7], $this->workDir, false);

        $this->assertSame([], $result->files);
        $this->assertSame(hash('sha256', 'abc'), $result->rows[0]['sha256']);
        $this->assertSame(0, self::countFiles($this->workDir), 'no copy stays behind');
    }

    public function testNameOfThePackageFileIsSanitizedAndTheOriginalNameKept(): void
    {
        $this->medium(8, 'folder/../meta.json', 'x');
        mkdir($this->workDir, 0700, true);

        $result = $this->exporter()->export([8], $this->workDir, true);

        $this->assertSame('folder/../meta.json', $result->entries[8]->meta->filename);
        $this->assertSame('file-8-meta.json', $result->entries[8]->fileName);
    }

    public function testCarriesTheDataOfTheMedium(): void
    {
        $media = $this->medium(9, 'photo.jpg', 'photo');
        $version = $this->currentVersion($media);
        $version->addMeta((new FileVersionMeta())->setLocale('de')->setTitle('Foto')->setDescription('Beschreibung')->setCopyright('Autor')->setCredits(null));
        $version->addMeta((new FileVersionMeta())->setLocale('en')->setTitle('Photo'));
        $version->setFocusPointX(25)->setFocusPointY(75);
        $version->addFormatOptions((new FormatOptions())->setFormatKey('sulu-400x400')->setCropX(1)->setCropY(2)->setCropWidth(300)->setCropHeight(200));
        $version->addTag((new Tag())->setName('Tutorial'));
        $version->setProperties(['source' => 'camera']);

        $category = (new Category())->setKey('screens');
        $category->addTranslation((new CategoryTranslation())->setLocale('de')->setTranslation('Bildschirme'));
        $category->addTranslation((new CategoryTranslation())->setLocale('en')->setTranslation('Screens'));
        $version->addCategory($category);
        mkdir($this->workDir, 0700, true);

        $meta = $this->exporter()->export([9], $this->workDir, true)->entries[9]->meta;

        $this->assertSame('Foto', $meta->meta['de']['title']);
        $this->assertSame('Beschreibung', $meta->meta['de']['description']);
        $this->assertSame('Autor', $meta->meta['de']['copyright']);
        $this->assertNull($meta->meta['de']['credits']);
        $this->assertSame('Photo', $meta->meta['en']['title']);
        $this->assertSame(['x' => 25, 'y' => 75], $meta->focusPoint);
        $this->assertSame(['sulu-400x400' => ['cropX' => 1, 'cropY' => 2, 'cropWidth' => 300, 'cropHeight' => 200]], $meta->formatOptions);
        $this->assertSame(['Tutorial'], $meta->tags);
        $this->assertSame([['key' => 'screens', 'names' => ['de' => 'Bildschirme', 'en' => 'Screens']]], $meta->categories);
        $this->assertSame(['source' => 'camera'], $meta->properties);
        $this->assertSame('screenshots', $meta->collection['key']);
        $this->assertSame([['de' => 'Anleitungen', 'en' => 'Tutorials'], ['de' => 'Bildschirmfotos', 'en' => 'Screenshots']], array_map(static function (array $titles): array {
            ksort($titles);

            return $titles;
        }, $meta->collection['titlePath']));
    }

    public function testNoFocusPointWhenOnlyOneCoordinateIsSet(): void
    {
        $media = $this->medium(10, 'a.jpg', 'a');
        $this->currentVersion($media)->setFocusPointX(10);
        mkdir($this->workDir, 0700, true);

        $this->assertNull($this->exporter()->export([10], $this->workDir, false)->entries[10]->meta->focusPoint);
    }

    public function testUnknownMediaAreReportedAndTheRestIsExported(): void
    {
        $this->medium(7, 'a.jpg', 'a');
        mkdir($this->workDir, 0700, true);

        $result = $this->exporter()->export([7, 404, 7], $this->workDir, false);

        $this->assertSame([7], array_keys($result->entries));
        $this->assertSame([404], $result->missing);
    }

    public function testMediumWithoutFileIsReportedAsMissing(): void
    {
        $this->media[5] = new Media();
        mkdir($this->workDir, 0700, true);

        $result = $this->exporter()->export([5], $this->workDir, false);

        $this->assertSame([5], $result->missing);
    }
}
