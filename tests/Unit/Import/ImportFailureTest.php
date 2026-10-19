<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Import\ImportFailure;
use PHPUnit\Framework\TestCase;

class ImportFailureTest extends TestCase
{
    public function testMessagesOfTheBundleAreKept(): void
    {
        $this->assertSame(
            'The tag "Guide" could not be created.',
            ImportFailure::describe(new ContentTransferException('The tag "Guide" could not be created.', 'x.y')),
        );
    }

    public function testDatabaseErrorsAreReplacedByAGenericText(): void
    {
        $pdo = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key ar_articles.uuid');

        $this->assertSame(ImportFailure::DATABASE_ERROR, ImportFailure::describe($pdo));
        // also when wrapped by something else
        $this->assertSame(ImportFailure::DATABASE_ERROR, ImportFailure::describe(new \RuntimeException('Could not save', 0, $pdo)));
    }

    /**
     * @dataProvider messagesWithPaths
     */
    public function testPathsOfTheServerAreBlankedOut(string $message, string $expected): void
    {
        $this->assertSame($expected, ImportFailure::describe(new \RuntimeException($message)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messagesWithPaths(): iterable
    {
        yield 'unix' => ['Unable to read /var/www/example/var/storage/a.jpg now', 'Unable to read … now'];
        yield 'windows backslash' => ['Cannot open C:\Sites\example\var\x.json: denied', 'Cannot open …: denied'];
        yield 'windows slash' => ['Cannot open D:/Sites/example/x.json', 'Cannot open …'];
        yield 'unc' => ['Cannot open \\\\server\share\file.zip', 'Cannot open …'];
        yield 'quoted' => ['The file "/home/user/app/a.txt" is missing', 'The file "…" is missing'];
        yield 'plain text stays' => ['Expected a boolean. Got: string', 'Expected a boolean. Got: string'];
        yield 'mime type and short slash stay' => ['The type image/jpeg or and/or a/b stays', 'The type image/jpeg or and/or a/b stays'];
    }

    public function testLongMessagesAreShortened(): void
    {
        $text = ImportFailure::describe(new \RuntimeException(str_repeat('word ', 300)));

        $this->assertSame(500, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
    }
}
