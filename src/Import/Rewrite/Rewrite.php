<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Rewrite;

/**
 * What happens to one reference of the package on this system: it stays as it is, it points to something else, or it
 * is removed because its target is not available.
 */
final class Rewrite
{
    public const KEEP = 'keep';
    public const REPLACE = 'replace';
    public const REMOVE = 'remove';

    /**
     * @param self::KEEP|self::REPLACE|self::REMOVE $action
     */
    private function __construct(
        public readonly string $action,
        public readonly int|string|null $value = null,
    ) {
    }

    public static function keep(): self
    {
        return new self(self::KEEP);
    }

    public static function replace(int|string $value): self
    {
        return new self(self::REPLACE, $value);
    }

    public static function remove(): self
    {
        return new self(self::REMOVE);
    }
}
