<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Security;

use Manuxi\SuluContentTransferBundle\Exception\ExportDeniedException;
use Manuxi\SuluContentTransferBundle\Export\ArticleTemplates;
use Manuxi\SuluContentTransferBundle\Security\ArticleSecurityContexts;
use Manuxi\SuluContentTransferBundle\Security\ExportAccess;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;

class ExportAccessTest extends TestCase
{
    private const BLOG = '4b1c0b3a-0000-4000-8000-000000000001';
    private const FAQ = '4b1c0b3a-0000-4000-8000-000000000002';

    /** @var list<string> */
    private array $checked = [];

    public function testEverythingAllowed(): void
    {
        $scope = $this->access($this->templates(), [])->resolve([self::BLOG, self::FAQ], []);

        $this->assertSame(['de', 'en'], $scope->available);
        $this->assertSame(['de', 'en'], $scope->locales);
    }

    public function testViewPermissionIsCheckedInTheContextOfTheGroupAndTheLanguage(): void
    {
        $this->access($this->templates(), [])->resolve([self::BLOG, self::FAQ], ['de']);

        $this->assertContains(ArticleAdmin::getArticleSecurityContext('blog').':de:'.PermissionTypes::VIEW, $this->checked);
        $this->assertContains(ArticleAdmin::getArticleSecurityContext('faq').':en:'.PermissionTypes::VIEW, $this->checked);
    }

    public function testArticleOfAGroupWithoutViewPermissionDeniesTheRequest(): void
    {
        $access = $this->access($this->templates(), [ArticleAdmin::getArticleSecurityContext('faq')]);

        foreach ([[], ['de'], ['en']] as $locales) {
            try {
                $access->resolve([self::BLOG, self::FAQ], $locales);
                $this->fail('The export of an article the user may not view must be denied.');
            } catch (ExportDeniedException $exception) {
                $this->assertSame([self::FAQ], $exception->uuids);
                $this->assertSame('export.denied', $exception->getReason());
            }
        }
    }

    public function testAllowedArticleAloneIsStillExportable(): void
    {
        $access = $this->access($this->templates(), [ArticleAdmin::getArticleSecurityContext('faq')]);

        $this->assertSame(['de', 'en'], $access->resolve([self::BLOG], [])->locales);
    }

    public function testRequestedLanguageThatIsDeniedForAnArticleThatHasItDeniesTheRequest(): void
    {
        $access = $this->access($this->templates(), [], ['en']);

        $this->expectException(ExportDeniedException::class);
        $access->resolve([self::BLOG], ['en']);
    }

    public function testWithoutRequestedLanguagesDeniedLanguagesAreNotOffered(): void
    {
        $scope = $this->access($this->templates(), [], ['en'])->resolve([self::BLOG], []);

        $this->assertSame(['de'], $scope->available);
        $this->assertSame(['de'], $scope->locales);
    }

    public function testRequestedLanguageThatNoArticleHasIsLeftToTheExporter(): void
    {
        $scope = $this->access($this->templates(), [], ['en'])->resolve([self::BLOG], ['nl']);

        $this->assertSame(['nl'], $scope->locales);
        $this->assertSame(['de'], $scope->available);
    }

    public function testDeniedLanguagesNeverTurnIntoAnEmptyListThatMeansEverything(): void
    {
        $access = $this->access(['4b1c0b3a-0000-4000-8000-000000000003' => ['de' => 'blog-post']], [], ['de']);

        $this->expectException(ExportDeniedException::class);
        $access->resolve(['4b1c0b3a-0000-4000-8000-000000000003'], []);
    }

    public function testUnknownArticlesAreLeftToTheExporter(): void
    {
        $scope = $this->access($this->templates(), [])->resolve([self::BLOG, '4b1c0b3a-0000-4000-8000-0000000000ff'], []);

        $this->assertSame(['de', 'en'], $scope->locales);
    }

    public function testFilterViewableKeepsArticlesThatAreViewableInOneLanguage(): void
    {
        $access = $this->access($this->templates(), [ArticleAdmin::getArticleSecurityContext('faq')]);
        $this->assertSame([self::BLOG], $access->filterViewable([self::BLOG, self::FAQ]));

        $access = $this->access($this->templates(), [], ['de']);
        $this->assertSame([self::BLOG, self::FAQ], $access->filterViewable([self::BLOG, self::FAQ]));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function templates(): array
    {
        return [
            self::BLOG => ['de' => 'blog-post', 'en' => 'blog-post'],
            self::FAQ => ['en' => 'faq-question'],
        ];
    }

    /**
     * @param array<string, array<string, string>> $templates
     * @param list<string>                         $deniedContexts contexts without any permission
     * @param list<string>                         $deniedLocales  languages without permission in every context
     */
    private function access(array $templates, array $deniedContexts, array $deniedLocales = []): ExportAccess
    {
        $checker = $this->createMock(SecurityCheckerInterface::class);
        $checker->method('hasPermission')->willReturnCallback(function (SecurityCondition $condition, string $permission) use ($deniedContexts, $deniedLocales): bool {
            $this->checked[] = $condition->getSecurityContext().':'.$condition->getLocale().':'.$permission;

            return !\in_array($condition->getSecurityContext(), $deniedContexts, true)
                && !\in_array($condition->getLocale(), $deniedLocales, true);
        });

        $provider = $this->createMock(GroupProviderInterface::class);
        $provider->method('getGroups')->willReturn([
            'blog' => new FormGroup('blog', 'Blog', ['blog-post']),
            'faq' => new FormGroup('faq', 'FAQ', ['faq-question']),
        ]);

        $source = new class($templates) implements ArticleTemplates {
            /** @param array<string, array<string, string>> $templates */
            public function __construct(private readonly array $templates)
            {
            }

            public function templateKeys(array $uuids): array
            {
                return array_intersect_key($this->templates, array_flip($uuids));
            }
        };

        return new ExportAccess($checker, new ArticleSecurityContexts($provider), $source);
    }
}
