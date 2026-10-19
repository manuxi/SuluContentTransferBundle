<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Security;

use Manuxi\SuluContentTransferBundle\Exception\ImportDeniedException;
use Manuxi\SuluContentTransferBundle\Format\ManifestSource;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleAction;
use Manuxi\SuluContentTransferBundle\Import\Plan\ArticleState;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanLocale;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Security\ArticleImportAccess;
use Manuxi\SuluContentTransferBundle\Security\ArticleSecurityContexts;
use Manuxi\SuluContentTransferBundle\Security\ImportAccess;
use Manuxi\SuluContentTransferBundle\Security\MediaCollectionLookup;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;

class ImportAccessTest extends TestCase
{
    private const BLOG = '4b1c0b3a-0000-4000-8000-000000000001';
    private const FAQ = '4b1c0b3a-0000-4000-8000-000000000002';
    private const SYSTEM_FOLDER = 90;
    private const MEDIUM_FOLDER = 31;

    /** @var list<string> every check as "context:locale:permission[:objectId]" */
    private array $checked = [];

    public function testEverythingAllowed(): void
    {
        $access = $this->access([])->describe($this->plan());

        $this->assertSame(['de', 'en'], $access[self::BLOG]->add);
        $this->assertSame(['de', 'en'], $access[self::BLOG]->edit);
        $this->assertSame(['de', 'en'], $access[self::BLOG]->live);
        $this->assertTrue($access[self::BLOG]->mediaCreate);
        $this->assertNull($access[self::BLOG]->denial(ArticleAction::CREATE, ['de', 'en'], ['de'], true));
    }

    public function testPermissionsAreCheckedInTheContextOfTheGroupAndTheLanguage(): void
    {
        $this->access([])->describe($this->plan());

        $blog = ArticleAdmin::getArticleSecurityContext('blog');
        $faq = ArticleAdmin::getArticleSecurityContext('faq');
        foreach ([PermissionTypes::ADD, PermissionTypes::EDIT, PermissionTypes::LIVE] as $permission) {
            $this->assertContains($blog.':de:'.$permission, $this->checked);
            $this->assertContains($blog.':en:'.$permission, $this->checked);
            $this->assertContains($faq.':en:'.$permission, $this->checked);
        }
        // the FAQ article is only imported in English
        $this->assertNotContains($faq.':de:'.PermissionTypes::ADD, $this->checked);
    }

    public function testCreatingNeedsTheAddPermissionAndUpdatingTheEditPermission(): void
    {
        $blog = ArticleAdmin::getArticleSecurityContext('blog');
        $access = $this->access([$blog.'::'.PermissionTypes::ADD])->describe($this->plan());

        $this->assertSame([], $access[self::BLOG]->add);
        $this->assertSame(ArticleImportAccess::DENIED_ADD, $access[self::BLOG]->denial(ArticleAction::CREATE, ['de'], [], false));
        $this->assertSame(ArticleImportAccess::DENIED_ADD, $access[self::BLOG]->denial(ArticleAction::COPY, ['de'], [], false));
        $this->assertNull($access[self::BLOG]->denial(ArticleAction::UPDATE, ['de'], [], false));
        $this->assertNull($access[self::BLOG]->denial(ArticleAction::SKIP, ['de'], [], false));

        $access = $this->access([$blog.'::'.PermissionTypes::EDIT])->describe($this->plan());
        $this->assertSame(ArticleImportAccess::DENIED_EDIT, $access[self::BLOG]->denial(ArticleAction::UPDATE, ['de'], [], false));
        $this->assertNull($access[self::BLOG]->denial(ArticleAction::CREATE, ['de'], [], false));
    }

    public function testALanguageWithoutPermissionDeniesOnlyWhenItIsImported(): void
    {
        $access = $this->access(['*:en:'.PermissionTypes::EDIT])->describe($this->plan());

        $this->assertSame(['de'], $access[self::BLOG]->edit);
        $this->assertSame(ArticleImportAccess::DENIED_EDIT, $access[self::BLOG]->denial(ArticleAction::UPDATE, ['de', 'en'], [], false));
        $this->assertNull($access[self::BLOG]->denial(ArticleAction::UPDATE, ['de'], [], false));
    }

    public function testPublishingNeedsTheLivePermissionOnlyWhenTheStatusIsKept(): void
    {
        $access = $this->access(['*:*:'.PermissionTypes::LIVE])->describe($this->plan());

        $this->assertSame([], $access[self::BLOG]->live);
        $this->assertNull($access[self::BLOG]->denial(ArticleAction::UPDATE, ['de'], [], false));
        $this->assertSame(ArticleImportAccess::DENIED_LIVE, $access[self::BLOG]->denial(ArticleAction::UPDATE, ['de'], ['de'], false));
    }

    public function testNewImagesNeedThePermissionToAddToTheirFolder(): void
    {
        $access = $this->access(['*:*:'.PermissionTypes::ADD.':'.self::SYSTEM_FOLDER])->describe($this->plan());

        $this->assertFalse($access[self::BLOG]->mediaCreate);
        $this->assertSame(ArticleImportAccess::DENIED_MEDIA, $access[self::BLOG]->denial(ArticleAction::UPDATE, ['de'], [], false));
        // the FAQ article uses no new image
        $this->assertTrue($access[self::FAQ]->mediaCreate);
        $this->assertNull($access[self::FAQ]->denial(ArticleAction::UPDATE, ['en'], [], false));
    }

    public function testExistingImagesAreOnlyEditedWhenTheirTextsAreOverwritten(): void
    {
        $access = $this->access(['*:*:'.PermissionTypes::EDIT.':'.self::MEDIUM_FOLDER])->describe($this->plan());

        $this->assertFalse($access[self::FAQ]->mediaTexts);
        $this->assertNull($access[self::FAQ]->denial(ArticleAction::UPDATE, ['en'], [], false));
        $this->assertSame(ArticleImportAccess::DENIED_MEDIA, $access[self::FAQ]->denial(ArticleAction::UPDATE, ['en'], [], true));
    }

    public function testNewFileVersionsOfExistingImagesNeedThePermissionToEditThem(): void
    {
        $access = $this->access(['*:*:'.PermissionTypes::EDIT.':'.self::MEDIUM_FOLDER])->describe($this->plan(newVersion: true));

        $this->assertFalse($access[self::FAQ]->mediaCreate);
        $this->assertSame(ArticleImportAccess::DENIED_MEDIA, $access[self::FAQ]->denial(ArticleAction::UPDATE, ['en'], [], false));
    }

    public function testEnforceKeepsWhatIsAllowed(): void
    {
        $decisions = new ImportDecisions([self::BLOG => ArticleAction::UPDATE], null, true, true);

        $enforced = $this->access([])->enforce($this->plan(), $decisions);

        $this->assertSame([self::BLOG => ArticleAction::UPDATE], $enforced->actions);
        $this->assertTrue($enforced->keepStatus);
        $this->assertTrue($enforced->overwriteMediaMeta);
    }

    public function testEnforceRefusesAnArticleThatWasAskedForByName(): void
    {
        $blog = ArticleAdmin::getArticleSecurityContext('blog');

        try {
            $this->access([$blog.'::'.PermissionTypes::EDIT])->enforce($this->plan(), new ImportDecisions([self::BLOG => ArticleAction::UPDATE]));
            $this->fail('An action the user may not carry out must be refused.');
        } catch (ImportDeniedException $exception) {
            $this->assertSame('import.denied', $exception->getReason());
            $this->assertSame([self::BLOG => ArticleImportAccess::DENIED_EDIT], $exception->reasons);
        }
    }

    public function testEnforceLeavesOutADeniedArticleThatHasOnlyADefault(): void
    {
        // the FAQ article is new: its default action is "create"
        $faq = ArticleAdmin::getArticleSecurityContext('faq');

        $enforced = $this->access([$faq.'::'.PermissionTypes::ADD])->enforce($this->plan(), new ImportDecisions());

        $this->assertSame(ArticleAction::SKIP, $enforced->actions[self::FAQ]);
        $this->assertArrayNotHasKey(self::BLOG, $enforced->actions);
    }

    public function testEnforceAlwaysAllowsSkipping(): void
    {
        $enforced = $this->access(['*:*:*'])->enforce($this->plan(), new ImportDecisions([self::BLOG => ArticleAction::SKIP, self::FAQ => ArticleAction::SKIP]));

        $this->assertSame([self::BLOG => ArticleAction::SKIP, self::FAQ => ArticleAction::SKIP], $enforced->actions);
    }

    public function testEnforceChecksOnlyTheLanguagesThatAreImported(): void
    {
        $enforced = $this->access(['*:en:'.PermissionTypes::EDIT])->enforce($this->plan(), new ImportDecisions([self::BLOG => ArticleAction::UPDATE], ['de']));

        $this->assertSame(ArticleAction::UPDATE, $enforced->actions[self::BLOG]);
        $this->assertSame(['de'], $enforced->locales);

        $this->expectException(ImportDeniedException::class);
        $this->access(['*:en:'.PermissionTypes::EDIT])->enforce($this->plan(), new ImportDecisions([self::BLOG => ArticleAction::UPDATE], ['de', 'en']));
    }

    public function testEnforceAsksForTheLivePermissionOnlyForLanguagesThatArePublished(): void
    {
        // the English version of the blog article is a draft in the package: it needs no permission to publish
        $access = $this->access(['*:en:'.PermissionTypes::LIVE]);

        $enforced = $access->enforce($this->plan(), new ImportDecisions([self::BLOG => ArticleAction::UPDATE], ['en'], true));
        $this->assertSame(ArticleAction::UPDATE, $enforced->actions[self::BLOG]);

        $this->expectException(ImportDeniedException::class);
        $this->access(['*:*:'.PermissionTypes::LIVE])->enforce($this->plan(), new ImportDecisions([self::BLOG => ArticleAction::UPDATE], ['de'], true));
    }

    public function testActionsThePlanDoesNotAllowAreLeftToTheDecisions(): void
    {
        $enforced = $this->access(['*:*:*'])->enforce($this->plan(), new ImportDecisions([self::BLOG => 'delete']));

        $this->assertSame('delete', $enforced->actions[self::BLOG]);
    }

    /**
     * @param list<string> $denied "context:locale:permission[:objectId]"; "*" matches everything, an empty part too
     */
    private function access(array $denied): ImportAccess
    {
        $checker = $this->createMock(SecurityCheckerInterface::class);
        $checker->method('hasPermission')->willReturnCallback(function (SecurityCondition $condition, string $permission) use ($denied): bool {
            $context = $condition->getSecurityContext();
            $locale = (string) $condition->getLocale();
            $objectId = null !== $condition->getObjectId() ? (string) $condition->getObjectId() : '';
            $this->checked[] = $context.':'.$locale.':'.$permission.('' !== $objectId ? ':'.$objectId : '');

            foreach ($denied as $rule) {
                $parts = explode(':', $rule);
                $parts += [null, null, null, null];
                [$ruleContext, $ruleLocale, $rulePermission, $ruleObject] = $parts;
                if (('' === $ruleContext || '*' === $ruleContext || $ruleContext === $context)
                    && ('' === $ruleLocale || '*' === $ruleLocale || $ruleLocale === $locale)
                    && ('*' === $rulePermission || $rulePermission === $permission)
                    && (null === $ruleObject || $ruleObject === $objectId)
                ) {
                    return false;
                }
            }

            return true;
        });

        $provider = $this->createMock(GroupProviderInterface::class);
        $provider->method('getGroups')->willReturn([
            'blog' => new FormGroup('blog', 'Blog', ['blog-post']),
            'faq' => new FormGroup('faq', 'FAQ', ['faq-question']),
        ]);

        $collections = $this->createMock(MediaCollectionLookup::class);
        $collections->method('collectionOfMedium')->willReturn(['id' => self::MEDIUM_FOLDER, 'system' => false]);
        $collections->method('collection')->willReturn(['id' => 12, 'system' => false]);

        $systemCollections = $this->createMock(SystemCollectionManagerInterface::class);
        $systemCollections->method('getSystemCollection')->willReturn(self::SYSTEM_FOLDER);

        return new ImportAccess($checker, new ArticleSecurityContexts($provider), $collections, $systemCollections);
    }

    private function plan(bool $newVersion = false): ImportPlan
    {
        $source = new ManifestSource('aaaabbbbccccdddd', 'source.example', '3.0.9', '1.0.0');
        $articles = [
            new PlanArticle(
                self::BLOG,
                ['de' => 'Blog', 'en' => 'Blog'],
                'blog-post',
                ArticleState::UNCHANGED,
                PlanArticle::MATCH_UUID,
                self::BLOG,
                ArticleAction::UPDATE,
                [ArticleAction::UPDATE, ArticleAction::COPY, ArticleAction::SKIP, ArticleAction::CREATE],
                [new PlanLocale('de', true, 'live', true), new PlanLocale('en', true, 'draft', false), new PlanLocale('fr', false, 'live', true, PlanLocale::REASON_LOCALE_UNKNOWN)],
                [],
                [],
                null,
                null,
            ),
            new PlanArticle(
                self::FAQ,
                ['en' => 'FAQ'],
                'faq-question',
                ArticleState::NEW,
                null,
                null,
                ArticleAction::CREATE,
                [ArticleAction::CREATE, ArticleAction::COPY, ArticleAction::SKIP, ArticleAction::UPDATE],
                [new PlanLocale('en', true, 'live', true)],
                [],
                [],
                null,
                null,
            ),
        ];
        $media = [
            // the blog article brings a new image, the FAQ article uses an existing one
            new PlanMedium(1, 'new.jpg', 10, 'a', true, PlanMedium::CREATE, null, null, ['type' => 'system', 'key' => 'sulu_content_transfer'], [self::BLOG]),
            new PlanMedium(2, 'old.jpg', 10, 'b', true, $newVersion ? PlanMedium::NEW_VERSION : PlanMedium::REUSE, 77, 'hash', null, [self::FAQ]),
        ];

        return new ImportPlan(
            str_repeat('a', 32),
            new \DateTimeImmutable('2026-10-19 10:00'),
            new \DateTimeImmutable('2026-10-20 10:00'),
            $source,
            new \DateTimeImmutable('2026-10-18 10:00'),
            [],
            false,
            'target',
            'target.example',
            $articles,
            $media,
            [],
            [],
            [],
            [],
        );
    }
}
