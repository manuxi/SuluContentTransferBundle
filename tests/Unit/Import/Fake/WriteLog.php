<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

/**
 * Records what the import writes, in order, across all the fake writers.
 */
final class WriteLog
{
    /** @var list<array{0: string, 1: string, 2: mixed}> kind, id, data */
    public array $calls = [];

    /** @var list<string> */
    public array $lines = [];

    public function add(string $kind, string $id, mixed $data, string $line): void
    {
        $this->calls[] = [$kind, $id, $data];
        $this->lines[] = $line;
    }

    /**
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    public function of(string $kind): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === $kind));
    }
}
