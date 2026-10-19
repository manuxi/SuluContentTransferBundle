<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\Manifest;
use Manuxi\SuluContentTransferBundle\Format\ManifestOptions;
use Manuxi\SuluContentTransferBundle\Format\ManifestSource;
use Manuxi\SuluContentTransferBundle\Format\MediaEntry;
use Manuxi\SuluContentTransferBundle\Format\MediaMeta;
use Manuxi\SuluContentTransferBundle\Format\Package;
use Manuxi\SuluContentTransferBundle\Format\ReferenceLookup;
use Manuxi\SuluContentTransferBundle\Import\MediaPlanner;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Tests\Support\ImportFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMappings;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMediaTarget;
use PHPUnit\Framework\TestCase;

class MediaPlannerTest extends TestCase
{
    private const SOURCE = '0123456789abcdef';

    private FakeMediaTarget $target;
    private FakeMappings $mappings;
    private MediaPlanner $planner;

    protected function setUp(): void
    {
        $this->target = new FakeMediaTarget();
        $this->mappings = new FakeMappings();
        $this->planner = new MediaPlanner($this->target, $this->mappings);
    }

    /**
     * @param array<int, string> $files medium id => content, the package carries these files
     * @param array<int, string> $named medium id => content, the package only names these images
     */
    private function package(array $files, array $named = [], ?string $collectionKey = 'tutorials'): Package
    {
        $media = [];
        $lookup = [];
        foreach ($files + $named as $id => $content) {
            $lookup[] = ['id' => $id, 'filename' => 'image-'.$id.'.jpg', 'size' => \strlen($content), 'sha256' => hash('sha256', $content)];
            if (isset($files[$id])) {
                $media[] = new MediaEntry($id, new MediaMeta('image-'.$id.'.jpg', 'image/jpeg', \strlen($content), hash('sha256', $content), [], null, [], ['Sulu'], [], [], ['key' => $collectionKey, 'titlePath' => []]), 'image-'.$id.'.jpg');
            }
        }

        $manifest = new Manifest(
            new \DateTimeImmutable('2026-10-18T15:30:00+02:00'),
            new ManifestSource(self::SOURCE, 'source', '3.0.9', '1.0.0'),
            new ManifestOptions(['de'], 'live', true),
            [ImportFixtures::item()],
            new ReferenceLookup(media: $lookup),
        );

        return new Package($manifest, [ImportFixtures::entry()], $media);
    }

    /**
     * @return array<int, list<string>>
     */
    private function usage(int ...$ids): array
    {
        return array_fill_keys($ids, [ImportFixtures::UUID]);
    }

    public function testImageWithTheSameHashIsReused(): void
    {
        $this->target->media[40] = ['name' => 'image-1.jpg', 'size' => 5, 'sha256' => hash('sha256', 'hello')];

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertCount(1, $plan);
        $this->assertSame(PlanMedium::REUSE, $plan[0]->action);
        $this->assertSame(40, $plan[0]->targetId);
        $this->assertSame(PlanMedium::REASON_HASH, $plan[0]->reason);
    }

    public function testSameNameAndSizeButOtherContentIsNotTheSameImage(): void
    {
        $this->target->media[40] = ['name' => 'image-1.jpg', 'size' => 5, 'sha256' => hash('sha256', 'other')];

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(PlanMedium::CREATE, $plan[0]->action);
        $this->assertNull($plan[0]->targetId);
        $this->assertSame([40], $this->target->hashed, 'the candidate was compared by its hash');
    }

    public function testOnlyCandidatesWithTheSameNameAndSizeAreHashed(): void
    {
        $this->target->media[40] = ['name' => 'other.jpg', 'size' => 5, 'sha256' => hash('sha256', 'hello')];
        $this->target->media[41] = ['name' => 'image-1.jpg', 'size' => 6, 'sha256' => hash('sha256', 'hello')];

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(PlanMedium::CREATE, $plan[0]->action);
        $this->assertSame([], $this->target->hashed);
    }

    public function testTheMappingTableComesBeforeTheSearchByName(): void
    {
        $this->target->media[40] = ['name' => 'renamed.jpg', 'size' => 99, 'sha256' => hash('sha256', 'hello')];
        $this->mappings->add(new TransferMapping(self::SOURCE, 'media', '1', '40', new \DateTimeImmutable()));

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(PlanMedium::REUSE, $plan[0]->action);
        $this->assertSame(40, $plan[0]->targetId);
        $this->assertSame(PlanMedium::REASON_MAPPING, $plan[0]->reason);
    }

    public function testKnownMediumWhoseFileChangedGetsANewVersionNotASecondImage(): void
    {
        $this->target->media[40] = ['name' => 'image-1.jpg', 'size' => 3, 'sha256' => hash('sha256', 'old')];
        $this->mappings->add(new TransferMapping(self::SOURCE, 'media', '1', '40', new \DateTimeImmutable()));

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(PlanMedium::NEW_VERSION, $plan[0]->action);
        $this->assertSame(40, $plan[0]->targetId);
    }

    public function testKnownMediumWithoutFileInThePackageIsReusedAsItIs(): void
    {
        $this->target->media[40] = ['name' => 'image-1.jpg', 'size' => 3, 'sha256' => hash('sha256', 'old')];
        $this->mappings->add(new TransferMapping(self::SOURCE, 'media', '1', '40', new \DateTimeImmutable()));

        $plan = $this->planner->plan($this->package([], [1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(PlanMedium::REUSE, $plan[0]->action);
        $this->assertFalse($plan[0]->hasFile);
    }

    public function testMappingOfADeletedMediumFallsBackToTheSearch(): void
    {
        $this->mappings->add(new TransferMapping(self::SOURCE, 'media', '1', '999', new \DateTimeImmutable()));
        $this->target->media[40] = ['name' => 'image-1.jpg', 'size' => 5, 'sha256' => hash('sha256', 'hello')];

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(40, $plan[0]->targetId);
        $this->assertSame(PlanMedium::REASON_HASH, $plan[0]->reason);
    }

    public function testMappingOfAnotherSourceDoesNotCount(): void
    {
        $this->target->media[40] = ['name' => 'image-1.jpg', 'size' => 5, 'sha256' => hash('sha256', 'hello')];
        $this->mappings->add(new TransferMapping('ffffffffffffffff', 'media', '1', '41', new \DateTimeImmutable()));

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(40, $plan[0]->targetId);
    }

    public function testNewImageGoesToTheFolderWithTheSameKey(): void
    {
        $this->target->collections['tutorials'] = 12;

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(['type' => 'collection', 'id' => 12], $plan[0]->collection);
    }

    public function testNewImageWithoutAFolderOfThatKeyGoesToTheImportSystemCollection(): void
    {
        $plan = $this->planner->plan($this->package([1 => 'hello'], [], null), self::SOURCE, $this->usage(1));
        $this->assertSame(['type' => 'system', 'key' => 'sulu_content_transfer'], $plan[0]->collection);

        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, $this->usage(1));
        $this->assertSame('system', $plan[0]->collection['type'], 'the key "tutorials" does not exist on this system');
    }

    public function testImageThatIsOnlyNamedAndNotOnThisSystemIsMissing(): void
    {
        $plan = $this->planner->plan($this->package([], [1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(PlanMedium::MISSING, $plan[0]->action);
        $this->assertFalse($plan[0]->hasFile);
    }

    public function testImageThatIsOnlyNamedIsFoundOnThisSystemByHash(): void
    {
        $this->target->media[40] = ['name' => 'image-1.jpg', 'size' => 5, 'sha256' => hash('sha256', 'hello')];

        $plan = $this->planner->plan($this->package([], [1 => 'hello']), self::SOURCE, $this->usage(1));

        $this->assertSame(PlanMedium::REUSE, $plan[0]->action);
        $this->assertSame(40, $plan[0]->targetId);
    }

    public function testImageWithoutAnyDescriptionIsMissing(): void
    {
        $plan = $this->planner->plan($this->package([1 => 'x']), self::SOURCE, $this->usage(1, 77));

        $this->assertSame([1, 77], array_map(static fn (PlanMedium $m): int => $m->sourceId, $plan));
        $this->assertSame(PlanMedium::MISSING, $plan[1]->action);
        $this->assertNull($plan[1]->filename);
    }

    public function testResolutionsFollowTheActions(): void
    {
        $this->target->media[40] = ['name' => 'image-1.jpg', 'size' => 5, 'sha256' => hash('sha256', 'hello')];

        $plan = $this->planner->plan($this->package([1 => 'hello', 2 => 'new'], [3 => 'named']), self::SOURCE, $this->usage(1, 2, 3));
        $resolutions = MediaPlanner::resolutions($plan);

        $this->assertSame([PlanResolution::MAPPED, PlanResolution::PLANNED, PlanResolution::REMOVED], array_map(static fn (PlanResolution $r): string => $r->status, $resolutions));
        $this->assertSame(40, $resolutions[0]->targetId);
        $this->assertSame('media:2', $resolutions[1]->ref);
    }

    public function testUsageIsKeptPerImage(): void
    {
        $plan = $this->planner->plan($this->package([1 => 'hello']), self::SOURCE, [1 => ['b', 'a', 'a']]);

        $this->assertSame(['a', 'b'], $plan[0]->usedBy);
    }
}
