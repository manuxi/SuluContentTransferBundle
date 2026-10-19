<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Import\UniqueSlug;
use PHPUnit\Framework\TestCase;

class UniqueSlugTest extends TestCase
{
    public function testFreeAddressStaysAsItIs(): void
    {
        $this->assertSame('/a/b', UniqueSlug::propose('/a/b', static fn (): bool => false));
    }

    public function testAppendsTheNextFreeNumberLikeSuluDoes(): void
    {
        $taken = ['/a/b' => true, '/a/b-1' => true, '/a/b-2' => true];

        $this->assertSame('/a/b-3', UniqueSlug::propose('/a/b', static fn (string $slug): bool => isset($taken[$slug])));
    }

    public function testGapsAreFilledFromTheLowestNumber(): void
    {
        $taken = ['/a/b' => true, '/a/b-2' => true];

        $this->assertSame('/a/b-1', UniqueSlug::propose('/a/b', static fn (string $slug): bool => isset($taken[$slug])));
    }

    public function testGivesUpWhenEverythingIsTaken(): void
    {
        $this->expectException(\RuntimeException::class);

        UniqueSlug::propose('/a', static fn (): bool => true);
    }
}
