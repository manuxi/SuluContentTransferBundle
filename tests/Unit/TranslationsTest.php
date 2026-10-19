<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class TranslationsTest extends TestCase
{
    private const DIRECTORY = __DIR__.'/../../src/Resources/translations/';

    public function testGermanAndEnglishHaveTheSameKeys(): void
    {
        $de = $this->load('admin.de.yaml');
        $en = $this->load('admin.en.yaml');

        $this->assertSame(array_keys($de), array_keys($en));
    }

    /**
     * @dataProvider languages
     */
    public function testPlaceholdersMatchBetweenTheLanguages(string $key): void
    {
        $de = $this->load('admin.de.yaml');
        $en = $this->load('admin.en.yaml');

        $this->assertSame($this->placeholders($en[$key]), $this->placeholders($de[$key]), $key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        foreach (array_keys(Yaml::parseFile(self::DIRECTORY.'admin.en.yaml')['sulu_content_transfer']) as $key) {
            yield $key => [$key];
        }
    }

    public function testTextsAreNotEmptyAndHaveNoApostrophes(): void
    {
        foreach (['admin.de.yaml', 'admin.en.yaml'] as $file) {
            foreach ($this->load($file) as $key => $text) {
                $this->assertNotSame('', trim($text), $key);
                // an apostrophe is an escape character in the message format of the admin
                $this->assertStringNotContainsString("'", $text, $file.' '.$key);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function load(string $file): array
    {
        return Yaml::parseFile(self::DIRECTORY.$file)['sulu_content_transfer'];
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{(\w+)[,}]/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
