<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit;

use Manuxi\SuluContentTransferBundle\Import\Plan\PlanLocale;
use Manuxi\SuluContentTransferBundle\Security\ArticleImportAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every code the admin can meet (reasons of errors, remarks of the analysis, reasons to leave a language out, reasons of
 * missing permissions) has a text in both languages, every text the dialog asks for exists, and every text is a valid
 * message of the message format of the admin.
 */
class TranslationCoverageTest extends TestCase
{
    private const SOURCE = __DIR__.'/../../src/';

    /** Strings that look like reasons but are not: file names, paths in error messages of the format, console only. */
    private const NOT_REASONS = [
        'plan.json', 'plan.article', 'plan.category', 'plan.media', 'plan.notice', 'plan.resolution', 'plan.url', 'plan.locale',
        'package.zip', 'import.action_invalid',
        // reasons for one article, not for a request: they have the texts "denied_*"
        'import.denied_add', 'import.denied_edit', 'import.denied_live', 'import.denied_media',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        yield 'de' => ['admin.de.yaml'];
        yield 'en' => ['admin.en.yaml'];
    }

    /**
     * @dataProvider languages
     */
    public function testEveryReasonOfAnErrorHasAText(string $file): void
    {
        $texts = $this->texts($file);

        $missing = [];
        foreach ($this->reasons() as $reason) {
            $key = 'error_'.str_replace('.', '_', $reason);
            if (!isset($texts[$key])) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'texts for the reasons of errors are missing in '.$file);
    }

    /**
     * @dataProvider languages
     */
    public function testEveryRemarkOfTheAnalysisHasAText(string $file): void
    {
        $texts = $this->texts($file);

        $codes = [];
        foreach ($this->phpFiles() as $path) {
            preg_match_all('/new Notice\(\s*Notice::[A-Z]+,\s*\'([a-z_.]+)\'/', (string) file_get_contents($path), $matches);
            array_push($codes, ...$matches[1]);
        }
        $codes = array_values(array_unique($codes));
        $this->assertGreaterThan(10, \count($codes), 'the remarks of the analysis should be found in the source');

        $missing = [];
        foreach ($codes as $code) {
            if (!isset($texts['notice_'.str_replace('.', '_', $code)])) {
                $missing[] = 'notice_'.str_replace('.', '_', $code);
            }
        }

        $this->assertSame([], $missing, $file);
    }

    /**
     * @dataProvider languages
     */
    public function testEveryReasonToLeaveALanguageOutAndEveryMissingPermissionHasAText(string $file): void
    {
        $texts = $this->texts($file);

        foreach ((new \ReflectionClass(PlanLocale::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'REASON_')) {
                $this->assertArrayHasKey('skipped_'.$value, $texts, $name);
            }
        }
        $this->assertArrayHasKey('skipped_skipped', $texts, 'the fallback reason of a skipped language');

        foreach ((new \ReflectionClass(ArticleImportAccess::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'DENIED_')) {
                $this->assertArrayHasKey('denied_'.str_replace('import.denied_', '', (string) $value), $texts, $name);
            }
        }
    }

    /**
     * @dataProvider languages
     */
    public function testEveryTextTheDialogAsksForExists(string $file): void
    {
        $texts = $this->texts($file);

        $keys = [];
        foreach (glob(self::SOURCE.'Resources/js/{,*/}*.js', \GLOB_BRACE) ?: [] as $path) {
            preg_match_all('/PREFIX\}\.([a-z_]+)/', (string) file_get_contents($path), $matches);
            array_push($keys, ...$matches[1]);
        }
        $keys = array_values(array_unique($keys));
        $this->assertGreaterThan(40, \count($keys), 'the texts of the dialog should be found in the source');

        $missing = [];
        foreach ($keys as $key) {
            if (str_ends_with($key, '_')) {
                // a family of texts built from a value, e.g. action_<action>
                if ([] === array_filter(array_keys($texts), static fn (string $text): bool => str_starts_with($text, $key))) {
                    $missing[] = $key.'*';
                }
            } elseif (!isset($texts[$key])) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, $file);
    }

    /**
     * @dataProvider languages
     */
    public function testEveryTextIsAValidMessage(string $file): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            $this->markTestSkipped('The intl extension is not available.');
        }

        $invalid = [];
        foreach ($this->texts($file) as $key => $text) {
            // the stubs of PHPStan do not know that create() returns null for a message that cannot be parsed
            if (null === @\MessageFormatter::create(str_contains($file, '.de.') ? 'de' : 'en', $text)) { // @phpstan-ignore-line
                $invalid[] = $key;
            }
        }

        $this->assertSame([], $invalid, $file);
    }

    /**
     * @return list<string>
     */
    private function reasons(): array
    {
        $reasons = [];
        foreach ($this->phpFiles() as $path) {
            preg_match_all('/\'((?:package|plan|import|export|workdir|transfer)\.[a-z_]+)\'/', (string) file_get_contents($path), $matches);
            array_push($reasons, ...$matches[1]);
        }
        $reasons = array_values(array_diff(array_unique($reasons), self::NOT_REASONS));
        sort($reasons);
        $this->assertGreaterThan(20, \count($reasons), 'the reasons of errors should be found in the source');

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCE, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function texts(string $file): array
    {
        return Yaml::parseFile(self::SOURCE.'Resources/translations/'.$file)['sulu_content_transfer'];
    }
}
