<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Import\ArticlePlanner;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\MediaPlanner;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\Notice;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Import\PlanStore;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetPage;
use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeArticleTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMappings;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMediaTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeReferenceTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeSystemTarget;

class ImportAnalyzerTest extends TemporaryDirectoryTestCase
{
    private FakeArticleTarget $articles;
    private FakeMediaTarget $media;
    private FakeReferenceTarget $references;
    private FakeSystemTarget $system;
    private FakeMappings $mappings;
    private WorkDirectory $workDirectory;
    private PlanStore $store;
    private string $file;
    private string $instanceId = 'target-instance-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->articles = new FakeArticleTarget();
        $this->media = new FakeMediaTarget();
        $this->references = new FakeReferenceTarget();
        $this->system = new FakeSystemTarget();
        $this->mappings = new FakeMappings();
        $this->workDirectory = new WorkDirectory($this->workDir);
        $this->store = new PlanStore($this->workDirectory);

        $this->file = $this->directory.'/package.sulu-transfer.zip';
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);
        (new PackageWriter(new PackageLimits(), $this->workDirectory))->writeToFile($package, $this->file, $mediaFiles);
    }

    private function analyzer(): ImportAnalyzer
    {
        return new ImportAnalyzer(
            new PackageReader(new PackageLimits(), $this->workDirectory),
            $this->store,
            new InstanceIdentity('secret', $this->instanceId, 'target.example.org'),
            $this->articles,
            $this->references,
            new ArticlePlanner($this->articles, $this->system, $this->references, $this->mappings),
            new MediaPlanner($this->media, $this->mappings),
            $this->mappings,
        );
    }

    /**
     * @return list<string>
     */
    private function codes(ImportPlan $plan): array
    {
        return array_map(static fn (Notice $notice): string => $notice->code, $plan->notices);
    }

    public function testAnalysisOfAnEmptyTargetPlansEverythingAsNewAndRemovesWhatIsMissing(): void
    {
        $plan = $this->analyzer()->analyze($this->file);

        $this->assertFalse($plan->sameInstance);
        $this->assertSame('0123456789abcdef', $plan->source->instanceId);
        $this->assertSame('target.example.org', $plan->targetLabel);

        $article = $plan->article(PackageFixtures::ARTICLE_1);
        $this->assertNotNull($article);
        $this->assertSame(ArticleAction::CREATE, $article->defaultAction);
        $this->assertSame(['de', 'en'], $article->importLocales());
        $this->assertSame('/einblicke/erste-schritte', $article->urls['de']->proposed);

        $this->assertCount(1, $plan->media);
        $this->assertSame(PlanMedium::CREATE, $plan->media[0]->action);
        $this->assertTrue($plan->media[0]->hasFile);

        // the page 1 and the category 7 are not on this system
        $this->assertSame(PlanResolution::REMOVED, $plan->resolution('pages', 'aaaaaaaa-0000-4000-8000-000000000001')?->status);
        $category = $plan->resolution('categories', 7);
        $this->assertSame(PlanResolution::PLANNED, $category?->status);
        $this->assertSame(['key:tutorials' => null, 'key:beginners' => 'key:tutorials'], array_column(array_map(static fn ($c): array => [$c->ref, $c->parentRef], $plan->categories), 1, 0));
        $this->assertSame(['Sulu', 'Tutorial'], $plan->tags, 'tags of the new image are created too');

        $this->assertContains('reference.removed', $this->codes($plan));
        $this->assertFalse($plan->hasErrors());
    }

    public function testAnalysisOfAFullTargetReusesWhatIsThere(): void
    {
        $content = "not really a jpeg \x00\x01\xff binary";
        $this->media->media[90] = ['name' => 'Bild äö.jpg', 'size' => \strlen($content), 'sha256' => hash('sha256', $content)];
        $this->references->pages['aaaaaaaa-0000-4000-8000-000000000001'] = new TargetPage('aaaaaaaa-0000-4000-8000-000000000001', 'your_webspace', ['de' => '/leistungen']);
        $this->references->categoryKeys['beginners'] = 33;
        $this->references->tags = ['Sulu' => 1, 'Tutorial' => 2];

        $plan = $this->analyzer()->analyze($this->file);

        $this->assertSame(PlanMedium::REUSE, $plan->media[0]->action);
        $this->assertSame(90, $plan->resolution('media', 12)?->targetId);
        $this->assertSame(33, $plan->resolution('categories', 7)?->targetId);
        $this->assertSame([], $plan->categories);
        $this->assertSame([], $plan->tags);
        $this->assertSame([], $this->codes($plan));
    }

    public function testPackageFromThisSystemIsOnlyAllowedAsACopy(): void
    {
        $this->instanceId = '0123456789abcdef';

        $plan = $this->analyzer()->analyze($this->file);

        $this->assertTrue($plan->sameInstance);
        $this->assertSame([ArticleAction::COPY, ArticleAction::SKIP], $plan->article(PackageFixtures::ARTICLE_1)?->allowedActions);
        $this->assertContains('source.same_instance', $this->codes($plan));
    }

    public function testReferencesOfLanguagesThatAreNotImportedAreIgnored(): void
    {
        $this->system->webspaces = ['your_webspace' => ['de']];

        $plan = $this->analyzer()->analyze($this->file);

        // the page reference sits in the English dimension
        $this->assertNull($plan->resolution('pages', 'aaaaaaaa-0000-4000-8000-000000000001'));
        $this->assertNotNull($plan->resolution('categories', 7));
    }

    public function testPlanIsStoredUnderItsTokenAndCanBeLoadedAgain(): void
    {
        $plan = $this->analyzer()->analyze($this->file);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $plan->token);
        $this->assertFileExists($this->workDir.'/'.$plan->token.'/plan.json');
        $this->assertFileExists($this->workDir.'/'.$plan->token.'/manifest.json', 'the unpacked package stays next to the plan');

        $loaded = $this->store->load($plan->token);
        $this->assertEquals($plan->toArray(), $loaded->toArray());
        $this->assertSame(86400, $plan->expiresAt->getTimestamp() - $plan->createdAt->getTimestamp());
    }

    public function testExpiredPlanCannotBeLoadedAndIsRemoved(): void
    {
        $plan = $this->analyzer()->analyze($this->file);

        try {
            $this->store->load($plan->token, $plan->expiresAt->modify('+1 second'));
            $this->fail('An expired plan must not be loaded.');
        } catch (ContentTransferException $exception) {
            $this->assertSame('plan.expired', $exception->getReason());
        }
        $this->assertDirectoryDoesNotExist($this->workDir.'/'.$plan->token);
    }

    public function testUnknownOrInvalidTokenIsRejected(): void
    {
        foreach (['../etc', str_repeat('a', 32), 'x'] as $token) {
            try {
                $this->store->load($token);
                $this->fail('Token '.$token.' must be rejected.');
            } catch (ContentTransferException $exception) {
                $this->assertContains($exception->getReason(), ['plan.token_invalid', 'workdir.missing', 'plan.missing']);
            }
        }
    }

    public function testOldTokenDirectoriesAreRemovedByTheNextAnalysis(): void
    {
        $old = $this->analyzer()->analyze($this->file);
        $this->assertDirectoryExists($this->workDir.'/'.$old->token);
        touch($this->workDir.'/'.$old->token, time() - 90000);
        clearstatcache();

        $new = $this->analyzer()->analyze($this->file);

        $this->assertDirectoryDoesNotExist($this->workDir.'/'.$old->token);
        $this->assertDirectoryExists($this->workDir.'/'.$new->token);
    }

    public function testFailedAnalysisLeavesNothingInTheWorkDirectory(): void
    {
        $this->articles = new class() extends FakeArticleTarget {
            public function templateKeys(): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $this->analyzer()->analyze($this->file);
        } finally {
            $this->assertSame(0, self::countFiles($this->workDir), 'the unpacked package is removed again');
        }
    }

    public function testBrokenPackageIsRejectedBeforeAnythingIsPlanned(): void
    {
        file_put_contents($this->directory.'/bad.zip', 'no zip');

        $this->expectException(ContentTransferException::class);

        $this->analyzer()->analyze($this->directory.'/bad.zip');
    }
}
