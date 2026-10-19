<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

use Manuxi\SuluContentTransferBundle\Import\Target\SystemTarget;

final class FakeSystemTarget implements SystemTarget
{
    /** @var array<string, list<string>> */
    public array $webspaces = ['your_webspace' => ['de', 'en']];

    public ?string $default = 'your_webspace';

    public function webspaceKeys(): array
    {
        return array_keys($this->webspaces);
    }

    public function localesOf(string $webspace): array
    {
        return $this->webspaces[$webspace] ?? [];
    }

    public function defaultWebspace(): ?string
    {
        return $this->default;
    }
}
