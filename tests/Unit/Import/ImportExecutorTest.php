<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Exception\PlanChangedException;
use Manuxi\SuluContentTransferBundle\Format\ArticleDimension;
use Manuxi\SuluContentTransferBundle\Format\ArticleEntry;
use Manuxi\SuluContentTransferBundle\Format\ArticleReference;
use Manuxi\SuluContentTransferBundle\Format\Manifest;
use Manuxi\SuluContentTransferBundle\Format\ManifestItem;
use Manuxi\SuluContentTransferBundle\Format\Package;
use Manuxi\SuluContentTransferBundle\Import\ArticlePlanner;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\ImportExecutor;
use Manuxi\SuluContentTransferBundle\Import\MediaPlanner;
use Manuxi\SuluContentTransferBundle\Import\PlanStore;
use Manuxi\SuluContentTransferBundle\Import\Report\ArticleResult;
use Manuxi\SuluContentTransferBundle\Import\Report\MediaResult;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\ReferenceRewriter;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetArticle;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetDimension;
use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeArticleTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeArticleWriter;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeImportSupport;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMappings;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMediaTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMediaWriter;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeReferenceTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeSystemTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\WriteLog;

class ImportExecutorTest extends TemporaryDirectoryTestCase
{
    private const PAGE = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const SECOND = 'f1e2d3c4-b5a6-4978-8a9b-0c1d2e3f4a5b';
    private const COPY_UUID = '99999999-0000-4000-8000-000000000009';
    private const SOURCE_INSTANCE = '0123456789abcdef';

    private FakeArticleTarget $articles;
    private FakeMediaTarget $mediaTarget;
    private FakeReferenceTarget $references;
    private FakeMappings $mappings;
    private WriteLog $log;
    private FakeArticleWriter $articleWriter;
    private FakeMediaWriter $mediaWriter;
    private FakeImportSupport $support;
    private WorkDirectory $workDirectory;
    private string $instanceId = 'target-instance-01';
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articles = new FakeArticleTarget();
        $this->mediaTarget = new FakeMediaTarget();
        $this->references = new FakeReferenceTarget();
        $this->mappings = new FakeMappings();
        $this->log = new WriteLog();
        $this->articleWriter = new FakeArticleWriter($this->log, $this->articles);
        $this->mediaWriter = new FakeMediaWriter($this->log);
        $this->support = new FakeImportSupport($this->log);
        $this->workDirectory = new WorkDirectory($this->workDir);
        $this->file = $this->directory.'/package.sulu-transfer.zip';

        $this->writePackage($this->package());
    }

    /**
     * The package of the fixtures with articles that carry references of many kinds.
     */
    private function package(bool $second = false): Package
    {
        ['package' => $base] = PackageFixtures::package($this->directory);

        $changed = '2026-10-17T09:00:00+00:00';
        $de = new ArticleDimension('de', 'draft', false, null, [
            'id' => PackageFixtures::ARTICLE_1,
            'template' => 'tutorial',
            'title' => 'Erste Schritte',
            'url' => '/einblicke/erste-schritte',
            'mainWebspace' => 'your_webspace',
            'changed' => $changed,
            'version' => 0,
            'stage' => 'draft',
            'locale' => 'de',
            'availableLocales' => ['de', 'en'],
            'blocks' => [['type' => 'image', 'image' => ['id' => 12]]],
            'excerptCategories' => [7],
            'excerptTags' => [3],
            'relatedPages' => [self::PAGE],
            'selection' => new \stdClass(),
        ]);
        $en = new ArticleDimension('en', 'live', true, new \DateTimeImmutable($changed), [
            'template' => 'tutorial',
            'title' => 'First steps',
            'url' => '/insights/first-steps',
            'mainWebspace' => 'your_webspace',
            'changed' => $changed,
            'body' => '<p><sulu-link provider="page" href="'.self::PAGE.'">Services</sulu-link></p>',
        ]);
        $references = [
            new ArticleReference('de', 'blocks.0.image.id', 'media', 12),
            new ArticleReference('de', 'excerptCategories.0', 'categories', 7),
            new ArticleReference('de', 'excerptTags.0', 'tags', 3),
            new ArticleReference('de', 'relatedPages.0', 'pages', self::PAGE),
            new ArticleReference('en', 'body', 'pages', self::PAGE),
        ];

        $articles = [new ArticleEntry(PackageFixtures::ARTICLE_1, new \DateTimeImmutable($changed), [$de, $en], $references)];
        $items = $base->manifest->items;
        if ($second) {
            $articles[] = new ArticleEntry(self::SECOND, new \DateTimeImmutable($changed), [
                new ArticleDimension('en', 'live', true, new \DateTimeImmutable($changed), [
                    'template' => 'tutorial',
                    'title' => 'Second',
                    'url' => '/insights/second',
                    'mainWebspace' => 'your_webspace',
                    'changed' => $changed,
                ]),
            ]);
            $items[] = new ManifestItem('articles', self::SECOND, 'tutorial', ['en' => 'Second']);
        }

        $manifest = $base->manifest;

        return new Package(
            new Manifest($manifest->createdAt, $manifest->source, $manifest->options, $items, $manifest->references),
            $articles,
            array_values($base->media),
        );
    }

    private function writePackage(Package $package): void
    {
        $content = "not really a jpeg \x00\x01\xff binary";
        $mediaFile = $this->directory.'/source-image.jpg';
        file_put_contents($mediaFile, $content);
        (new PackageWriter(new PackageLimits(), $this->workDirectory))->writeToFile($package, $this->file, [] === $package->media ? [] : [12 => $mediaFile]);
    }

    private function analyzer(): ImportAnalyzer
    {
        return new ImportAnalyzer(
            new PackageReader(new PackageLimits(), $this->workDirectory),
            new PlanStore($this->workDirectory),
            new InstanceIdentity('secret', $this->instanceId, 'target.example.org'),
            $this->articles,
            $this->references,
            new ArticlePlanner($this->articles, new FakeSystemTarget(), $this->references, $this->mappings),
            new MediaPlanner($this->mediaTarget, $this->mappings),
            $this->mappings,
        );
    }

    private function executor(): ImportExecutor
    {
        return new ImportExecutor(
            new PackageReader(new PackageLimits(), $this->workDirectory),
            new PlanStore($this->workDirectory),
            $this->analyzer(),
            $this->support,
            $this->support,
            $this->mediaWriter,
            $this->articleWriter,
            $this->support,
            $this->articles,
            $this->references,
            $this->mappings,
            new ReferenceRewriter(),
            $this->support,
            static fn (): string => self::COPY_UUID,
        );
    }

    private function analyze(): string
    {
        return $this->analyzer()->analyze($this->file)->token;
    }

    public function testImportsANewArticleAsADraft(): void
    {
        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(), 'editor');

        $this->assertFalse($report->hasFailures());
        $this->assertSame('editor', $this->support->username);

        // taxonomy first (parents before children), then the image, then the article: first language created, others modified
        $this->assertSame([
            'category tutorials parent=-',
            'category beginners parent=500',
            'tag Sulu',
            'tag Tutorial',
            'media 700 collection=99',
            'create '.PackageFixtures::ARTICLE_1.' de',
            'modify '.PackageFixtures::ARTICLE_1.' en',
        ], $this->log->lines);

        $created = $this->log->of('create')[0][2];
        $this->assertSame('de', $created['locale']);
        $this->assertSame('tutorial', $created['template']);
        $this->assertSame('your_webspace', $created['mainWebspace']);
        // the fields of the system stay out
        foreach (['id', 'stage', 'availableLocales', 'changed', 'version'] as $key) {
            $this->assertArrayNotHasKey($key, $created);
        }
        // every reference points to what was created here
        $this->assertSame(700, $created['blocks'][0]['image']['id']);
        $this->assertSame([501], $created['excerptCategories']);
        $this->assertSame([600], $created['excerptTags']);
        $this->assertSame([], $created['relatedPages'], 'the page does not exist here');
        $this->assertSame([], $created['selection'], 'an empty object stays an empty selection');
        $this->assertSame('/einblicke/erste-schritte', $created['url']);

        $modified = $this->log->of('modify')[0][2];
        $this->assertSame('<p>Services</p>', $modified['body'], 'the link to the missing page is taken out of the text');

        // nothing was published
        $this->assertSame([], $this->log->of('publish'));

        $article = $report->articles[0];
        $this->assertSame(ArticleResult::CREATED, $article->status);
        $this->assertSame(PackageFixtures::ARTICLE_1, $article->targetUuid);
        $this->assertSame(['de', 'en'], $article->locales);
        $this->assertSame([], $article->published);
        $this->assertCount(2, $article->removed);
        $this->assertSame('/admin/#/de/articles/'.PackageFixtures::ARTICLE_1, $article->adminLinks['de']);

        $this->assertSame(MediaResult::CREATED, $report->media[0]->outcome);
        $this->assertSame(700, $report->media[0]->targetId);
        $this->assertSame(['tutorials', 'beginners'], array_column($report->categories, 'key'));
        $this->assertSame('Tutorials > Einsteiger', $report->categories[1]['path']);
        $this->assertSame(['Sulu', 'Tutorial'], array_column($report->tags, 'name'));

        // the image file that reached the writer is the file of the package
        $this->assertSame("not really a jpeg \x00\x01\xff binary", $this->log->of('media')[0][2]['content']);
        $this->assertSame([600, 601], $this->log->of('media')[0][2]['tags']);

        // the next analysis finds the article and the image again
        $mapping = $this->mappings->findMapping(self::SOURCE_INSTANCE, 'articles', PackageFixtures::ARTICLE_1);
        $this->assertSame(PackageFixtures::ARTICLE_1, $mapping?->getTargetId());
        $this->assertSame('2026-10-17T09:00:00+00:00', $mapping->getSourceChanged()?->format(\DATE_ATOM));
        $this->assertSame('700', $this->mappings->findMapping(self::SOURCE_INSTANCE, 'media', '12')?->getTargetId());

        // the unpacked package is gone
        $this->assertSame(0, self::countFiles($this->workDir));
    }

    public function testKeepStatusPublishesOnlyWhatWasPublishedOnTheSource(): void
    {
        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(keepStatus: true), 'editor');

        $this->assertSame([['publish', PackageFixtures::ARTICLE_1, 'en']], $this->log->of('publish'));
        $this->assertSame(['en'], $report->articles[0]->published);
    }

    public function testOnlyTheChosenLanguagesAreWritten(): void
    {
        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(locales: ['en']), 'editor');

        $this->assertSame(['create '.PackageFixtures::ARTICLE_1.' en'], array_values(array_filter($this->log->lines, static fn (string $line): bool => str_starts_with($line, 'create') || str_starts_with($line, 'modify'))));
        $this->assertSame(['en'], $report->articles[0]->locales);
        // the German draft is not imported, so its tag, category and image are not created either
        $this->assertSame([], $this->log->of('category'));
        $this->assertSame([], $this->log->of('media'));
    }

    public function testSkippedArticleWritesNothing(): void
    {
        $report = $this->executor()->execute($this->analyze(), new ImportDecisions([PackageFixtures::ARTICLE_1 => 'skip']), 'editor');

        $this->assertSame([], $this->log->lines);
        $this->assertSame(ArticleResult::SKIPPED, $report->articles[0]->status);
        $this->assertSame([], $report->media);
        $this->assertNull($this->mappings->findMapping(self::SOURCE_INSTANCE, 'articles', PackageFixtures::ARTICLE_1));
    }

    public function testCopyGetsANewUuidAndAFreeAddress(): void
    {
        $this->articles->articles[PackageFixtures::ARTICLE_1] = new TargetArticle(PackageFixtures::ARTICLE_1, [new TargetDimension('de', 'live', new \DateTimeImmutable('2026-10-18T09:00:00+00:00'))]);
        $this->articles->routes['de|/einblicke/erste-schritte'] = 'articles:'.PackageFixtures::ARTICLE_1;

        $report = $this->executor()->execute($this->analyze(), new ImportDecisions([PackageFixtures::ARTICLE_1 => 'copy']), 'editor');

        $article = $report->articles[0];
        $this->assertSame(ArticleResult::COPIED, $article->status);
        $this->assertSame(self::COPY_UUID, $article->targetUuid);
        $this->assertSame('create '.self::COPY_UUID.' de', $this->log->lines[array_key_last(array_filter($this->log->lines, static fn (string $line): bool => str_starts_with($line, 'create')))]);
        $this->assertSame('/einblicke/erste-schritte-1', $this->log->of('create')[0][2]['url']);
        $this->assertSame(['from' => '/einblicke/erste-schritte', 'to' => '/einblicke/erste-schritte-1'], $article->renamedUrls['de']);
        // the original stays the partner of the source: the copy does not take its place
        $this->assertNull($this->mappings->findMapping(self::SOURCE_INSTANCE, 'articles', PackageFixtures::ARTICLE_1));
    }

    public function testCopyOfANewArticleBecomesItsPartnerForLaterImports(): void
    {
        $this->executor()->execute($this->analyze(), new ImportDecisions([PackageFixtures::ARTICLE_1 => 'copy']), 'editor');

        $this->assertSame(self::COPY_UUID, $this->mappings->findMapping(self::SOURCE_INSTANCE, 'articles', PackageFixtures::ARTICLE_1)?->getTargetId());
    }

    public function testUpdateWritesIntoTheMatchedArticleAndRefreshesTheMapping(): void
    {
        $this->articles->articles[PackageFixtures::ARTICLE_1] = new TargetArticle(PackageFixtures::ARTICLE_1, [new TargetDimension('de', 'draft', new \DateTimeImmutable('2026-10-16T09:00:00+00:00'))]);
        $this->articles->routes['de|/einblicke/erste-schritte'] = 'articles:'.PackageFixtures::ARTICLE_1;

        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(), 'editor');

        $this->assertSame(ArticleResult::UPDATED, $report->articles[0]->status);
        $this->assertSame([], $this->log->of('create'));
        $this->assertCount(2, $this->log->of('modify'));
        // it is the article's own address: no renaming
        $this->assertSame('/einblicke/erste-schritte', $this->log->of('modify')[0][2]['url']);
        $this->assertSame([], $report->articles[0]->renamedUrls);
    }

    public function testRenamesAnAddressThatAnotherArticleOfTheSameRunTook(): void
    {
        // both articles ask for /insights/first-steps in English
        $second = $this->package(true);
        $entries = $second->articles;
        $entries[self::SECOND] = new ArticleEntry(self::SECOND, $entries[self::SECOND]->changed, [
            new ArticleDimension('en', 'live', true, new \DateTimeImmutable('2026-10-17T09:00:00+00:00'), [
                'template' => 'tutorial', 'title' => 'Second', 'url' => '/insights/first-steps', 'mainWebspace' => 'your_webspace', 'changed' => '2026-10-17T09:00:00+00:00',
            ]),
        ]);
        $this->writePackage(new Package($second->manifest, array_values($entries), array_values($second->media)));

        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(), 'editor');

        $this->assertFalse($report->hasFailures());
        $byUuid = [];
        foreach ($report->articles as $article) {
            $byUuid[$article->sourceUuid] = $article;
        }
        $this->assertSame([], $byUuid[PackageFixtures::ARTICLE_1]->renamedUrls);
        $this->assertSame(['en' => ['from' => '/insights/first-steps', 'to' => '/insights/first-steps-1']], $byUuid[self::SECOND]->renamedUrls);
        $this->assertSame('/insights/first-steps-1', $this->log->of('create')[1][2]['url']);
    }

    public function testAnErrorOfOneArticleRollsBackThatArticleAndTheOthersGoOn(): void
    {
        $this->writePackage($this->package(true));
        $this->articleWriter->failFor = [PackageFixtures::ARTICLE_1];

        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(), 'editor');

        $this->assertTrue($report->hasFailures());
        $byUuid = [];
        foreach ($report->articles as $article) {
            $byUuid[$article->sourceUuid] = $article;
        }
        $this->assertSame(ArticleResult::FAILED, $byUuid[PackageFixtures::ARTICLE_1]->status);
        $this->assertStringContainsString('Sulu said no', (string) $byUuid[PackageFixtures::ARTICLE_1]->message);
        $this->assertSame(ArticleResult::CREATED, $byUuid[self::SECOND]->status);
        $this->assertSame(1, $this->support->rollbacks);
        // nothing of the failed article counts as written, and it has no mapping row
        $this->assertNull($this->mappings->findMapping(self::SOURCE_INSTANCE, 'articles', PackageFixtures::ARTICLE_1));
        $this->assertNotNull($this->mappings->findMapping(self::SOURCE_INSTANCE, 'articles', self::SECOND));
    }

    public function testRefusesToStartWhenTheStateChangedSinceTheAnalysis(): void
    {
        $token = $this->analyze();
        // somebody created the article in the meantime
        $this->articles->articles[PackageFixtures::ARTICLE_1] = new TargetArticle(PackageFixtures::ARTICLE_1, [new TargetDimension('de', 'live', new \DateTimeImmutable('2026-10-18T09:00:00+00:00'))]);

        try {
            $this->executor()->execute($token, new ImportDecisions(), 'editor');
            $this->fail('The import should have been refused.');
        } catch (PlanChangedException $exception) {
            $this->assertSame('plan.changed', $exception->getReason());
        }
        $this->assertSame([], $this->log->lines);
    }

    public function testUnknownUserWritesNothing(): void
    {
        $token = $this->analyze();

        $this->expectException(ContentTransferException::class);
        $this->expectExceptionMessage('There is no user "nobody"');

        try {
            $this->executor()->execute($token, new ImportDecisions(), 'nobody');
        } finally {
            $this->assertSame([], $this->log->lines);
        }
    }

    public function testRefusesAnActionThePlanDoesNotAllow(): void
    {
        $token = $this->analyze();

        try {
            // a new article cannot be "updated"
            $this->executor()->execute($token, new ImportDecisions([PackageFixtures::ARTICLE_1 => 'update']), 'editor');
            $this->fail('The action should have been refused.');
        } catch (ContentTransferException $exception) {
            $this->assertSame('import.action_not_allowed', $exception->getReason());
        }
        $this->assertSame([], $this->log->lines);
    }

    public function testRefusesAnActionForAnArticleThatIsNotInThePackage(): void
    {
        $token = $this->analyze();

        $this->expectException(ContentTransferException::class);
        $this->executor()->execute($token, new ImportDecisions(['00000000-0000-4000-8000-000000000000' => 'skip']), 'editor');
    }

    public function testReusedImagesKeepTheirTextsUnlessAskedToOverwrite(): void
    {
        $content = "not really a jpeg \x00\x01\xff binary";
        $this->mediaTarget->media[90] = ['name' => 'Bild äö.jpg', 'size' => \strlen($content), 'sha256' => hash('sha256', $content)];

        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(), 'editor');
        $this->assertSame(MediaResult::REUSED, $report->media[0]->outcome);
        $this->assertSame(90, $report->media[0]->targetId);
        $this->assertSame([], $this->log->of('media'));
        $this->assertSame([], $this->log->of('media_texts'));
        $this->assertSame(90, $this->log->of('create')[0][2]['blocks'][0]['image']['id']);
        $this->assertSame('90', $this->mappings->findMapping(self::SOURCE_INSTANCE, 'media', '12')?->getTargetId());

        $this->setUp();
        $this->mediaTarget->media[90] = ['name' => 'Bild äö.jpg', 'size' => \strlen($content), 'sha256' => hash('sha256', $content)];
        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(overwriteMediaMeta: true), 'editor');
        $this->assertTrue($report->media[0]->textsUpdated);
        $this->assertSame([['media_texts', '90', ['de' => ['title' => 'Ein Bild', 'description' => null, 'copyright' => 'Example', 'credits' => null]]]], $this->log->of('media_texts'));
    }

    public function testImageWithoutFileAndNotOnThisSystemIsRemovedFromTheContent(): void
    {
        $package = $this->package();
        $this->writePackage(new Package($package->manifest, array_values($package->articles), []));

        $report = $this->executor()->execute($this->analyze(), new ImportDecisions(), 'editor');

        $this->assertSame(MediaResult::MISSING, $report->media[0]->outcome);
        $created = $this->log->of('create')[0][2];
        $this->assertNull($created['blocks'][0]['image']);
        $this->assertContains('media', array_map(static fn ($reference): string => $reference->kind, $report->articles[0]->removed));
        $this->assertSame([], $this->log->of('media'));
    }
}
