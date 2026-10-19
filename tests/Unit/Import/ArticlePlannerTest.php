<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Entity\TransferMapping;
use Manuxi\SuluContentTransferBundle\Format\ReferenceLookup;
use Manuxi\SuluContentTransferBundle\Import\AnalysisContext;
use Manuxi\SuluContentTransferBundle\Import\ArticlePlanner;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleState;
use Manuxi\SuluContentTransferBundle\Import\Plan\Notice;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanLocale;
use Manuxi\SuluContentTransferBundle\Import\ReferenceResolver;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetArticle;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetDimension;
use Manuxi\SuluContentTransferBundle\Import\Target\TargetPage;
use Manuxi\SuluContentTransferBundle\Tests\Support\ImportFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeArticleTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMappings;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeReferenceTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeSystemTarget;
use PHPUnit\Framework\TestCase;

class ArticlePlannerTest extends TestCase
{
    private const SOURCE = '0123456789abcdef';
    private const UUID = ImportFixtures::UUID;
    private const COPY_OF = 'f1e2d3c4-b5a6-4978-8a9b-0c1d2e3f4a5b';

    private FakeArticleTarget $articles;
    private FakeSystemTarget $system;
    private FakeReferenceTarget $references;
    private FakeMappings $mappings;
    private ArticlePlanner $planner;

    protected function setUp(): void
    {
        $this->articles = new FakeArticleTarget();
        $this->system = new FakeSystemTarget();
        $this->references = new FakeReferenceTarget();
        $this->mappings = new FakeMappings();
        $this->planner = new ArticlePlanner($this->articles, $this->system, $this->references, $this->mappings);

        $this->references->pages[ImportFixtures::PAGE] = new TargetPage(ImportFixtures::PAGE, 'your_webspace', ['de' => '/ziel', 'en' => '/target']);
    }

    /**
     * @param list<string> $templates
     */
    private function context(bool $sameInstance = false, array $templates = ['tutorial']): AnalysisContext
    {
        return new AnalysisContext(
            self::SOURCE,
            $sameInstance,
            $templates,
            new ReferenceResolver($this->references, $this->mappings, self::SOURCE, new ReferenceLookup(), []),
        );
    }

    private function dimension(string $locale, string $stage, string $changed, ?string $by = null): TargetDimension
    {
        return new TargetDimension($locale, $stage, new \DateTimeImmutable($changed), $by);
    }

    /**
     * @param list<string> $codes
     */
    private function assertCodes(array $codes, \Manuxi\SuluContentTransferBundle\Import\ArticlePlanResult $result): void
    {
        $this->assertSame($codes, array_map(static fn (Notice $notice): string => $notice->code, $result->notices));
    }

    public function testArticleThatIsNotOnThisSystemIsNewAndCreated(): void
    {
        $result = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context());
        $article = $result->article;

        $this->assertSame(ArticleState::NEW, $article->state);
        $this->assertNull($article->targetId);
        $this->assertSame(ArticleAction::CREATE, $article->defaultAction);
        $this->assertSame([ArticleAction::CREATE, ArticleAction::COPY, ArticleAction::SKIP], $article->allowedActions);
        $this->assertSame(['de', 'en'], $article->importLocales());
        $this->assertSame('/ziel/article-de', $article->urls['de']->requested, 'the page of this system, not the path in the package');
        $this->assertSame('/target/article-en', $article->urls['en']->proposed);
        $this->assertCodes([], $result);
    }

    public function testSameUuidWithoutAnyChangeIsUpdated(): void
    {
        $this->articles->articles[self::UUID] = new TargetArticle(self::UUID, [
            $this->dimension('de', 'live', '2026-10-17T09:00:00+00:00'),
            $this->dimension('de', 'draft', '2026-10-17T09:00:00+00:00'),
        ]);

        $article = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context())->article;

        $this->assertSame(ArticleState::UNCHANGED, $article->state);
        $this->assertSame(PlanArticle::MATCH_UUID, $article->matchedBy);
        $this->assertSame(self::UUID, $article->targetId);
        $this->assertSame(ArticleAction::UPDATE, $article->defaultAction);
        $this->assertSame([ArticleAction::UPDATE, ArticleAction::COPY, ArticleAction::SKIP], $article->allowedActions);
    }

    public function testDifferenceOfOneSecondIsNoChange(): void
    {
        $this->articles->articles[self::UUID] = new TargetArticle(self::UUID, [$this->dimension('de', 'live', '2026-10-17T09:00:01+00:00')]);

        $this->assertSame(ArticleState::UNCHANGED, $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context())->article->state);
    }

    public function testVersionOfTheSameStageChangedAfterThePackageStateIsAConflict(): void
    {
        $this->articles->articles[self::UUID] = new TargetArticle(self::UUID, [
            $this->dimension('de', 'live', '2026-10-17T09:00:00+00:00'),
            $this->dimension('en', 'live', '2026-10-20T10:30:00+00:00', 'M. Bertrams'),
        ]);

        $article = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context())->article;

        $this->assertSame(ArticleState::CHANGED, $article->state);
        $this->assertSame('2026-10-20T10:30:00+00:00', $article->changedAt?->format(\DATE_ATOM));
        $this->assertSame('M. Bertrams', $article->changedBy);
        $this->assertSame(ArticleAction::SKIP, $article->defaultAction);
        $this->assertSame([ArticleAction::SKIP, ArticleAction::UPDATE, ArticleAction::COPY], $article->allowedActions);
    }

    public function testNewerDraftOnTheTargetDoesNotCountAgainstALivePackage(): void
    {
        $this->articles->articles[self::UUID] = new TargetArticle(self::UUID, [
            $this->dimension('de', 'live', '2026-10-17T09:00:00+00:00'),
            $this->dimension('de', 'draft', '2026-10-30T09:00:00+00:00'),
        ]);

        $this->assertSame(ArticleState::UNCHANGED, $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context())->article->state);
    }

    public function testMappingComesBeforeTheSameUuid(): void
    {
        $this->articles->articles[self::UUID] = new TargetArticle(self::UUID);
        $this->articles->articles[self::COPY_OF] = new TargetArticle(self::COPY_OF);
        $this->mappings->add(new TransferMapping(self::SOURCE, 'articles', self::UUID, self::COPY_OF, new \DateTimeImmutable('2026-10-18T00:00:00+00:00')));

        $article = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context())->article;

        $this->assertSame(PlanArticle::MATCH_MAPPING, $article->matchedBy);
        $this->assertSame(self::COPY_OF, $article->targetId);
    }

    public function testMappingOfAnotherSourceIsIgnored(): void
    {
        $this->articles->articles[self::UUID] = new TargetArticle(self::UUID);
        $this->articles->articles[self::COPY_OF] = new TargetArticle(self::COPY_OF);
        $this->mappings->add(new TransferMapping('ffffffffffffffff', 'articles', self::UUID, self::COPY_OF, new \DateTimeImmutable()));

        $this->assertSame(self::UUID, $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context())->article->targetId);
    }

    public function testMappingToADeletedArticleFallsBackToTheUuidAndSaysSo(): void
    {
        $this->mappings->add(new TransferMapping(self::SOURCE, 'articles', self::UUID, self::COPY_OF, new \DateTimeImmutable()));

        $result = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context());

        $this->assertSame(ArticleState::NEW, $result->article->state);
        $this->assertCodes(['article.mapping_stale'], $result);
    }

    public function testWithAMappingAnyVersionChangedAfterTheLastImportIsAConflict(): void
    {
        $this->articles->articles[self::COPY_OF] = new TargetArticle(self::COPY_OF, [
            $this->dimension('de', 'draft', '2026-10-19T12:00:00+00:00', 'Editor'),
        ]);
        $this->mappings->add(new TransferMapping(self::SOURCE, 'articles', self::UUID, self::COPY_OF, new \DateTimeImmutable('2026-10-19T11:00:00+00:00')));

        $article = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context())->article;

        $this->assertSame(ArticleState::CHANGED, $article->state);
        $this->assertSame('Editor', $article->changedBy);
        $this->assertSame(ArticleAction::SKIP, $article->defaultAction);
    }

    public function testWithAMappingNothingChangedSinceTheLastImportIsUpdatedAndAnOldPackageIsNoted(): void
    {
        $this->articles->articles[self::COPY_OF] = new TargetArticle(self::COPY_OF, [
            $this->dimension('de', 'draft', '2026-10-19T11:00:00+00:00'),
        ]);
        $this->mappings->add(new TransferMapping(self::SOURCE, 'articles', self::UUID, self::COPY_OF, new \DateTimeImmutable('2026-10-19T11:00:00+00:00'), new \DateTimeImmutable('2026-10-17T09:00:00+00:00')));

        $result = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context());

        $this->assertSame(ArticleState::UNCHANGED, $result->article->state);
        $this->assertSame(ArticleAction::UPDATE, $result->article->defaultAction);
        $this->assertCodes(['article.package_not_newer'], $result);
    }

    public function testPackageFromThisSystemMayOnlyBeImportedAsACopy(): void
    {
        $this->articles->articles[self::UUID] = new TargetArticle(self::UUID, [$this->dimension('de', 'live', '2026-10-17T09:00:00+00:00')]);
        $this->articles->routes['de|/ziel/article-de'] = 'articles:'.self::UUID;
        $this->articles->routes['en|/target/article-en'] = 'articles:'.self::UUID;

        $article = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context(true))->article;

        $this->assertSame(ArticleAction::COPY, $article->defaultAction);
        $this->assertSame([ArticleAction::COPY, ArticleAction::SKIP], $article->allowedActions);
        $this->assertSame('/ziel/article-de-1', $article->copyUrls['de']->proposed, 'the copy cannot take the address of the original');
        $this->assertSame('/ziel/article-de', $article->urls['de']->proposed, 'an update would keep the own address');
    }

    public function testMappingIsNotConsultedForAPackageFromThisSystem(): void
    {
        $this->articles->articles[self::COPY_OF] = new TargetArticle(self::COPY_OF);
        $this->mappings->add(new TransferMapping(self::SOURCE, 'articles', self::UUID, self::COPY_OF, new \DateTimeImmutable()));

        $this->assertNull($this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context(true))->article->targetId);
    }

    public function testAddressUsedByAnotherContentGetsTheNextFreeNumber(): void
    {
        $this->articles->routes['de|/ziel/article-de'] = 'pages:'.ImportFixtures::PAGE;
        $this->articles->routes['de|/ziel/article-de-1'] = 'articles:'.self::COPY_OF;

        $result = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context());
        $url = $result->article->urls['de'];

        $this->assertSame('/ziel/article-de', $url->requested);
        $this->assertSame('/ziel/article-de-2', $url->proposed);
        $this->assertSame('pages:'.ImportFixtures::PAGE, $url->takenBy);
        $this->assertTrue($url->isTaken());
        $this->assertFalse($result->article->urls['en']->isTaken());
        $this->assertCodes(['url.taken'], $result);
        $this->assertSame('de', $result->notices[0]->locale);
    }

    public function testTwoArticlesOfThePackageDoNotTakeTheSameAddress(): void
    {
        $context = $this->context();

        $first = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $context);
        $second = $this->planner->plan(ImportFixtures::entry(self::COPY_OF), ImportFixtures::item(self::COPY_OF), $context);

        $this->assertSame('/ziel/article-de', $first->article->urls['de']->proposed);
        $this->assertSame('/ziel/article-de-1', $second->article->urls['de']->proposed);
        $this->assertSame('package', $second->article->urls['de']->takenBy);
    }

    public function testAnArticleThatIsSkippedDoesNotClaimItsAddress(): void
    {
        $this->articles->articles[self::UUID] = new TargetArticle(self::UUID, [$this->dimension('de', 'live', '2026-11-01T00:00:00+00:00')]);
        $context = $this->context();

        $first = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $context);
        $second = $this->planner->plan(ImportFixtures::entry(self::COPY_OF), ImportFixtures::item(self::COPY_OF), $context);

        $this->assertSame(ArticleAction::SKIP, $first->article->defaultAction);
        $this->assertSame('/ziel/article-de', $second->article->urls['de']->proposed);
    }

    public function testAddressWithoutAPageIsTakenAsItIs(): void
    {
        $entry = ImportFixtures::entry(self::UUID, ['de' => ['url' => '/plain/address']]);

        $article = $this->planner->plan($entry, ImportFixtures::item(), $this->context())->article;

        $this->assertSame('/plain/address', $article->urls['de']->proposed);
        $this->assertNull($article->urls['de']->parentPage);
    }

    public function testLanguageWhosePageCannotBeFoundIsLeftOutWithAnError(): void
    {
        $this->references->pages = [];

        $result = $this->planner->plan(ImportFixtures::entry(self::UUID, ['de' => [], 'en' => ['url' => '/plain']]), ImportFixtures::item(), $this->context());

        $this->assertSame(['en'], $result->article->importLocales());
        $this->assertSame(PlanLocale::REASON_URL_PARENT_MISSING, $result->article->locales['de']->reason);
        $this->assertSame(Notice::ERROR, $result->notices[0]->level);
        $this->assertCodes(['url.parent_missing'], $result);
    }

    public function testLanguageTheWebspaceDoesNotHaveIsSkippedWithAHint(): void
    {
        $this->system->webspaces = ['your_webspace' => ['de']];

        $result = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context());

        $this->assertSame(['de'], $result->article->importLocales());
        $this->assertSame(PlanLocale::REASON_LOCALE_UNKNOWN, $result->article->locales['en']->reason);
        $this->assertCodes(['locale.unknown'], $result);
        $this->assertSame(ArticleState::NEW, $result->article->state);
    }

    public function testArticleWithoutAnyImportableLanguageIsBlocked(): void
    {
        $this->system->webspaces = ['your_webspace' => ['nl']];

        $result = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context());

        $this->assertSame(ArticleState::BLOCKED, $result->article->state);
        $this->assertSame([ArticleAction::SKIP], $result->article->allowedActions);
        $this->assertSame([], $result->article->importLocales());
        $this->assertContains('article.no_language', array_map(static fn (Notice $n): string => $n->code, $result->notices));
    }

    public function testUnknownTemplateBlocksTheArticle(): void
    {
        $result = $this->planner->plan(ImportFixtures::entry(), ImportFixtures::item(), $this->context(false, ['other']));
        $article = $result->article;

        $this->assertSame(ArticleState::BLOCKED, $article->state);
        $this->assertSame(ArticleAction::SKIP, $article->defaultAction);
        $this->assertSame([ArticleAction::SKIP], $article->allowedActions);
        $this->assertSame(PlanLocale::REASON_TEMPLATE_MISSING, $article->locales['de']->reason);
        $this->assertSame(Notice::ERROR, $result->notices[0]->level);
        $this->assertSame('template.missing', $result->notices[0]->code);
        $this->assertSame('tutorial', $result->notices[0]->params['templates']);
    }

    public function testWebspaceThatDoesNotExistFallsBackToTheDefaultOnceWithAHint(): void
    {
        $entry = ImportFixtures::entry(self::UUID, ['de' => ['mainWebspace' => 'gone'], 'en' => ['mainWebspace' => 'gone']]);

        $result = $this->planner->plan($entry, ImportFixtures::item(), $this->context());

        $this->assertSame('gone', $result->article->mainWebspace);
        $this->assertSame('your_webspace', $result->article->targetWebspace);
        $this->assertSame(['de', 'en'], $result->article->importLocales());
        $this->assertCodes(['webspace.fallback'], $result);
    }

    public function testMissingTemplateKeyInTheDataFallsBackToTheManifest(): void
    {
        $entry = ImportFixtures::entry(self::UUID, ['de' => ['template' => null]]);

        $this->assertSame(ArticleState::NEW, $this->planner->plan($entry, ImportFixtures::item(), $this->context())->article->state);
    }
}
