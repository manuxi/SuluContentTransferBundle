<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Manuxi\SuluContentTransferBundle\Import\Target\DoctrineMediaTarget;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\Filesystem\Exception\IOException;

class DoctrineMediaTargetTest extends TestCase
{
    /** @var array<int, Media> */
    private array $media = [];

    /** @var array<string, string> */
    private array $stored = [];

    /** @var list<string> */
    private array $loaded = [];

    private function target(): DoctrineMediaTarget
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturnCallback(fn (int $id): ?Media => $this->media[$id] ?? null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(MediaInterface::class)->willReturn($repository);

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('load')->willReturnCallback(function (array $options) {
            $this->loaded[] = $options['fileName'];
            if (!isset($this->stored[$options['fileName']])) {
                throw new IOException('gone');
            }
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $this->stored[$options['fileName']]);
            rewind($stream);

            return $stream;
        });

        return new DoctrineMediaTarget($entityManager, $storage);
    }

    private function medium(int $id, string $current, ?string $old = null, bool $stored = true): void
    {
        $file = new File();
        $file->setVersion(2);
        $file->addFileVersion((new FileVersion())->setVersion(1)->setName('a.jpg')->setStorageOptions(['fileName' => 'old-'.$id]));
        $file->addFileVersion((new FileVersion())->setVersion(2)->setName('a.jpg')->setStorageOptions(['fileName' => 'current-'.$id]));
        $this->stored['old-'.$id] = $old ?? 'previous version';
        if ($stored) {
            $this->stored['current-'.$id] = $current;
        }

        $media = new Media();
        $media->addFile($file);
        $this->media[$id] = $media;
    }

    public function testHashesTheFileOfTheCurrentVersionThroughTheStorage(): void
    {
        $this->medium(5, 'the current bytes');

        $this->assertSame(hash('sha256', 'the current bytes'), $this->target()->sha256(5));
        $this->assertSame(['current-5'], $this->loaded, 'only the current version is read');
    }

    public function testFileThatCannotBeReadGivesNoHash(): void
    {
        $this->medium(5, 'x', null, false);

        $this->assertNull($this->target()->sha256(5));
    }

    public function testUnknownMediumGivesNoHash(): void
    {
        $this->assertNull($this->target()->sha256(404));
    }

    public function testExistsAsksTheRepository(): void
    {
        $this->medium(5, 'x');

        $this->assertTrue($this->target()->exists(5));
        $this->assertFalse($this->target()->exists(6));
    }
}
