<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Format\ManifestSource;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleState;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\Notice;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanCategory;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanLocale;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanUrl;
use Manuxi\SuluContentTransferBundle\Import\PlanPresenter;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetArticle;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetDimension;
use Manuxi\SuluContentTransferBundle\Security\ArticleImportAccess;
use Manuxi\SuluContentTransferBundle\Security\ImportAccess;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeArticleTarget;
use PHPUnit\Framework\TestCase;

class PlanPresenterTest extends TestCase
{
    private const NEW = '0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8';
    private const EXISTING = 'f1e2d3c4-b5a6-4978-8a9b-0c1d2e3f4a5b';

    public function testPresentsThePackageTheArticlesAndTheSummaries(): void
    {
        $data = $this->presenter()->present($this->plan());

        $this->assertSame(str_repeat('a', 32), $data['token']);
        $this->assertEquals(new \DateTimeImmutable('2026-10-20 10:00'), new \DateTimeImmutable($data['expiresAt']));
        $this->assertEquals(new \DateTimeImmutable('2026-10-18 10:00'), new \DateTimeImmutable($data['package']['createdAt']));
        unset($data['package']['createdAt']);
        $this->assertSame(['label' => 'source.example', 'instanceId' => 'aaaabbbbccccdddd', 'suluVersion' => '3.0.9', 'sameInstance' => false], $data['package']);
        $this->assertSame(['label' => 'target.example'], $data['target']);
        $this->assertSame([['locale' => 'de', 'import' => true], ['locale' => 'en', 'import' => true], ['locale' => 'fr', 'import' => false]], $data['locales']);
        $this->assertSame(['Neu'], $data['tags']);
        $this->assertSame('reference.removed', $data['notices'][0]['code']);

        $this->assertCount(2, $data['articles']);
        [$new, $existing] = $data['articles'];
        $this->assertSame(self::NEW, $new['id']);
        $this->assertSame('Neuer Artikel', $new['title']);
        $this->assertSame('create', $new['defaultAction']);
        $this->assertSame(['create', 'copy', 'skip'], $new['allowedActions']);
        $this->assertSame([], $new['publishedOnTarget']);
        $this->assertSame(['add' => ['de'], 'edit' => [], 'live' => [], 'mediaCreate' => true, 'mediaTexts' => true], $new['access']);
        $this->assertSame('/a/neuer-artikel', $new['urls']['de']['requested']);

        $this->assertSame(self::EXISTING, $existing['targetId']);
        $this->assertSame('unchanged', $existing['state']);
    }

    public function testNamesTheLanguagesOfTheTargetArticleThatArePublished(): void
    {
        $data = $this->presenter()->present($this->plan());

        $this->assertSame(['de', 'en'], $data['articles'][1]['publishedOnTarget']);
    }

    public function testCountsTheImagesAndNamesTheFoldersOfNewOnes(): void
    {
        $media = $this->presenter()->present($this->plan())['media'];

        $this->assertSame(['total' => 4, 'reuse' => 1, 'create' => 1, 'newVersion' => 1, 'missing' => 1, 'folders' => ['system']], $media);
    }

    public function testShowsNewCategoriesWithTheChainOfTheirParents(): void
    {
        $categories = $this->presenter()->present($this->plan())['categories'];

        $this->assertSame([
            ['label' => 'Tutorials', 'path' => 'Tutorials'],
            ['label' => 'Einsteiger', 'path' => 'Tutorials › Einsteiger'],
        ], $categories);
    }

    private function presenter(): PlanPresenter
    {
        $access = $this->createMock(ImportAccess::class);
        $access->method('describe')->willReturn([
            self::NEW => new ArticleImportAccess(['de'], [], []),
            self::EXISTING => new ArticleImportAccess(['de', 'en'], ['de', 'en'], ['de']),
        ]);

        $target = new FakeArticleTarget();
        $target->articles[self::EXISTING] = new TargetArticle(self::EXISTING, [
            new TargetDimension('de', 'live', new \DateTimeImmutable('2026-10-01'), 'Editor'),
            new TargetDimension('de', 'draft', new \DateTimeImmutable('2026-10-02'), 'Editor'),
            new TargetDimension('en', 'live', new \DateTimeImmutable('2026-10-01'), 'Editor'),
            new TargetDimension('nl', 'draft', new \DateTimeImmutable('2026-10-01'), 'Editor'),
        ]);

        return new PlanPresenter($access, $target);
    }

    private function plan(): ImportPlan
    {
        $articles = [
            new PlanArticle(
                self::NEW,
                ['de' => 'Neuer Artikel'],
                'tutorial',
                ArticleState::NEW,
                null,
                null,
                ArticleAction::CREATE,
                [ArticleAction::CREATE, ArticleAction::COPY, ArticleAction::SKIP],
                [new PlanLocale('de', true, 'live', true), new PlanLocale('fr', false, 'live', true, PlanLocale::REASON_LOCALE_UNKNOWN)],
                ['de' => new PlanUrl('/a/neuer-artikel', '/a/neuer-artikel')],
                [],
                'your_webspace',
                'your_webspace',
            ),
            new PlanArticle(
                self::EXISTING,
                ['de' => 'Vorhanden', 'en' => 'Existing'],
                'tutorial',
                ArticleState::UNCHANGED,
                PlanArticle::MATCH_UUID,
                self::EXISTING,
                ArticleAction::UPDATE,
                [ArticleAction::UPDATE, ArticleAction::COPY, ArticleAction::SKIP],
                [new PlanLocale('de', true, 'live', true), new PlanLocale('en', true, 'live', true)],
                [],
                [],
                'your_webspace',
                'your_webspace',
            ),
        ];
        $media = [
            new PlanMedium(1, 'a.jpg', 1, 'a', true, PlanMedium::REUSE, 5, 'hash'),
            new PlanMedium(2, 'b.jpg', 1, 'b', true, PlanMedium::CREATE, null, null, ['type' => 'system', 'key' => 'sulu_content_transfer']),
            new PlanMedium(3, 'c.jpg', 1, 'c', true, PlanMedium::NEW_VERSION, 6, 'mapping'),
            new PlanMedium(4, 'd.jpg', 1, 'd', false, PlanMedium::MISSING),
        ];
        $categories = [
            new PlanCategory('key:tutorials', 'tutorials', ['de' => 'Tutorials']),
            new PlanCategory('key:beginners', 'beginners', ['de' => 'Einsteiger'], null, 'key:tutorials'),
        ];

        return new ImportPlan(
            str_repeat('a', 32),
            new \DateTimeImmutable('2026-10-19 10:00'),
            new \DateTimeImmutable('2026-10-20 10:00'),
            new ManifestSource('aaaabbbbccccdddd', 'source.example', '3.0.9', '1.0.0'),
            new \DateTimeImmutable('2026-10-18 10:00'),
            [],
            false,
            'target',
            'target.example',
            $articles,
            $media,
            $categories,
            ['Neu'],
            [],
            [new Notice(Notice::WARNING, 'reference.removed', 'Removed.', self::NEW)],
        );
    }
}
