<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

use Manuxi\SuluContentTransferBundle\Import\Target\MediaTarget;

final class FakeMediaTarget implements MediaTarget
{
    /** @var array<int, array{name: string, size: int, sha256: string|null}> */
    public array $media = [];

    /** @var array<string, int> */
    public array $collections = [];

    /** @var list<int> ids whose hash was computed */
    public array $hashed = [];

    public function exists(int $id): bool
    {
        return isset($this->media[$id]);
    }

    public function candidates(string $filename, int $size): array
    {
        $ids = [];
        foreach ($this->media as $id => $medium) {
            if ($medium['name'] === $filename && $medium['size'] === $size) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function sha256(int $id): ?string
    {
        $this->hashed[] = $id;

        return $this->media[$id]['sha256'] ?? null;
    }

    public function collectionIdByKey(string $key): ?int
    {
        return $this->collections[$key] ?? null;
    }
}
