<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Export;

use Manuxi\SuluContentTransferBundle\Export\PackageFileName;
use Manuxi\SuluContentTransferBundle\Package\PackagePath;
use PHPUnit\Framework\TestCase;

class PackageFileNameTest extends TestCase
{
    /**
     * @dataProvider names
     */
    public function testSanitizedNameIsAcceptedByThePackage(string $original, string $expected): void
    {
        $name = PackageFileName::sanitize($original, 42);

        $this->assertSame($expected, $name);
        $this->assertTrue(PackagePath::isSafeFileName($name), $name);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function names(): iterable
    {
        yield 'plain name stays' => ['Programming Code (2).jpg', 'Programming Code (2).jpg'];
        yield 'umlauts stay' => ['Grüße aus Köln.png', 'Grüße aus Köln.png'];
        yield 'separators and wildcards' => ['a:b*c?d"e<f>g|h.jpg', 'a_b_c_d_e_f_g_h.jpg'];
        yield 'path in the name keeps the last part' => ['../../etc/passwd.jpg', 'passwd.jpg'];
        yield 'windows path' => ['C:\\Users\\me\\photo.jpg', 'photo.jpg'];
        yield 'leading dot' => ['.htaccess', 'htaccess'];
        yield 'leading dots and spaces' => ['  ..photo.jpg', 'photo.jpg'];
        yield 'trailing dot' => ['photo.', 'photo'];
        yield 'space before the extension is fine' => ['photo .jpg', 'photo .jpg'];
        yield 'device name' => ['nul.jpg', 'file-42-nul.jpg'];
        yield 'reserved meta file' => ['meta.json', 'file-42-meta.json'];
        yield 'reserved meta file in other case' => ['META.JSON', 'file-42-META.JSON'];
        yield 'control characters' => ["a\x00b\x1fc.jpg", 'a_b_c.jpg'];
        yield 'only separators' => ['///', 'file-42'];
        yield 'empty' => ['', 'file-42'];
        yield 'only dots' => ['....', 'file-42'];
        yield 'extension only' => ['.jpg', 'jpg'];
    }

    public function testLongNamesAreShortenedAndKeepTheirExtension(): void
    {
        $name = PackageFileName::sanitize(str_repeat('ä', 200).'.jpeg', 7);

        $this->assertLessThanOrEqual(255, \strlen($name));
        $this->assertStringEndsWith('.jpeg', $name);
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
        $this->assertTrue(PackagePath::isSafeFileName($name));
    }

    public function testNamesInAnotherEncodingBecomeUtf8(): void
    {
        $name = PackageFileName::sanitize("K\xf6ln.jpg", 1);

        $this->assertSame('Köln.jpg', $name);
    }
}
