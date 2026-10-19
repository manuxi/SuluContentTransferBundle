<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Export;

use Manuxi\SuluContentTransferBundle\Export\ExportedArticle;
use Manuxi\SuluContentTransferBundle\Export\ExportFileName;
use PHPUnit\Framework\TestCase;

class ExportFileNameTest extends TestCase
{
    private const DATE = '2026-10-19';

    public function testNameIsDateAndSlugOfTheFirstTitle(): void
    {
        $name = ExportFileName::for([$this->article('Erste Schritte: Übersicht & mehr')], new \DateTimeImmutable(self::DATE));

        $this->assertSame('2026-10-19-erste-schritte-uebersicht-mehr.sulu-transfer.zip', $name);
    }

    public function testFirstArticleWithATitleCounts(): void
    {
        $name = ExportFileName::for([$this->article(''), $this->article('Second'), $this->article('Third')], new \DateTimeImmutable(self::DATE));

        $this->assertSame('2026-10-19-second.sulu-transfer.zip', $name);
    }

    public function testFallbackWithoutUsableTitle(): void
    {
        $this->assertSame('2026-10-19-articles.sulu-transfer.zip', ExportFileName::for([$this->article('???')], new \DateTimeImmutable(self::DATE)));
        $this->assertSame('2026-10-19-articles.sulu-transfer.zip', ExportFileName::for([], new \DateTimeImmutable(self::DATE)));
    }

    public function testSlugIsShortened(): void
    {
        $name = ExportFileName::for([$this->article(str_repeat('a', 200))], new \DateTimeImmutable(self::DATE));

        $this->assertSame('2026-10-19-'.str_repeat('a', 60).'.sulu-transfer.zip', $name);
    }

    public function testNameHasNoCharactersThatNeedEscaping(): void
    {
        $name = ExportFileName::for([$this->article("../etc/pass\"wd\r\n ß")], new \DateTimeImmutable(self::DATE));

        $this->assertMatchesRegularExpression('/^[a-z0-9.-]+$/', $name);
    }

    private function article(string $title): ExportedArticle
    {
        return new ExportedArticle('4b1c0b3a-0000-4000-8000-000000000001', $title, ['de' => ExportedArticle::STATUS_LIVE]);
    }
}
