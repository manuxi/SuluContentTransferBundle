<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Rewrite;

use Manuxi\SuluContentTransferBundle\Format\ManifestSource;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleState;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\PlanRewriteMap;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\Rewrite;
use PHPUnit\Framework\TestCase;

final class PlanRewriteMapTest extends TestCase
{
    /**
     * @param list<PlanResolution> $resolutions
     * @param list<PlanArticle>    $articles
     */
    private function plan(array $resolutions, array $articles = []): ImportPlan
    {
        $now = new \DateTimeImmutable('2026-10-19T10:00:00+00:00');

        return new ImportPlan(
            str_repeat('a', 32),
            $now,
            $now->modify('+1 day'),
            new ManifestSource('0123456789abcdef', 'source.example.org', '3.0.9', '1.0.0', null),
            $now,
            [],
            false,
            'target-instance-01',
            'target.example.org',
            $articles,
            [],
            [],
            [],
            $resolutions,
            [],
        );
    }

    public function testMapsEveryStatusOfTheResolutionTable(): void
    {
        $map = new PlanRewriteMap($this->plan([
            new PlanResolution('media', 1, PlanResolution::MAPPED, 91),
            new PlanResolution('media', 2, PlanResolution::PLANNED, null, 'media:2'),
            new PlanResolution('media', 3, PlanResolution::PLANNED, null, 'media:3'),
            new PlanResolution('media', 4, PlanResolution::REMOVED),
            new PlanResolution('accounts', 5, PlanResolution::UNCHECKED, 5),
            new PlanResolution('categories', 6, PlanResolution::PLANNED, null, 'key:beginners'),
            new PlanResolution('tags', 7, PlanResolution::PLANNED, null, 'tag:Sulu'),
            new PlanResolution('articles', 'bbbbbbbb-0000-4000-8000-00000000000b', PlanResolution::PACKAGE),
            new PlanResolution('articles', 'cccccccc-0000-4000-8000-00000000000c', PlanResolution::PACKAGE),
            new PlanResolution('pages', 'aaaaaaaa-0000-4000-8000-00000000000a', PlanResolution::MAPPED, 'dddddddd-0000-4000-8000-00000000000d'),
        ]));
        $map->addCreated('media:2', 701);
        $map->addCreated('key:beginners', 33);
        $map->addCreated('tag:Sulu', 12);
        $map->setArticleTarget('bbbbbbbb-0000-4000-8000-00000000000b', 'eeeeeeee-0000-4000-8000-00000000000e');
        $map->setArticleTarget('cccccccc-0000-4000-8000-00000000000c', null);

        $this->assertEquals(Rewrite::replace(91), $map->resolve('media', 1));
        $this->assertEquals(Rewrite::replace(701), $map->resolve('media', 2));
        $this->assertEquals(Rewrite::remove(), $map->resolve('media', 3), 'planned but never created');
        $this->assertEquals(Rewrite::remove(), $map->resolve('media', 4));
        $this->assertEquals(Rewrite::keep(), $map->resolve('accounts', 5));
        $this->assertEquals(Rewrite::replace(33), $map->resolve('categories', 6));
        $this->assertEquals(Rewrite::replace(12), $map->resolve('tags', 7));
        $this->assertEquals(Rewrite::replace('eeeeeeee-0000-4000-8000-00000000000e'), $map->resolve('articles', 'bbbbbbbb-0000-4000-8000-00000000000b'));
        $this->assertEquals(Rewrite::remove(), $map->resolve('articles', 'cccccccc-0000-4000-8000-00000000000c'));
        $this->assertEquals(Rewrite::replace('dddddddd-0000-4000-8000-00000000000d'), $map->resolve('pages', 'aaaaaaaa-0000-4000-8000-00000000000a'));
        $this->assertEquals(Rewrite::keep(), $map->resolve('unknown_kind', 9), 'what the plan does not know is kept');
        $this->assertEquals(Rewrite::keep(), $map->resolve('tags', 'Some tag'), 'tag names of smart content stay');
    }

    public function testDecisionsOnlyAllowWhatThePlanAllows(): void
    {
        $article = new PlanArticle(
            'aaaaaaaa-0000-4000-8000-00000000000a',
            ['de' => 'Titel'],
            'tutorial',
            ArticleState::CHANGED,
            PlanArticle::MATCH_UUID,
            'aaaaaaaa-0000-4000-8000-00000000000a',
            ArticleAction::SKIP,
            [ArticleAction::SKIP, ArticleAction::UPDATE, ArticleAction::COPY],
            [],
            [],
            [],
            null,
            null,
        );

        $this->assertSame(ArticleAction::SKIP, (new ImportDecisions())->actionFor($article));
        $this->assertSame(ArticleAction::UPDATE, (new ImportDecisions([$article->uuid => 'update']))->actionFor($article));

        $this->expectException(\Manuxi\SuluContentTransferBundle\Exception\ContentTransferException::class);
        (new ImportDecisions([$article->uuid => 'create']))->actionFor($article);
    }
}
