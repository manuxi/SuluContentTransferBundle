<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

/**
 * Makes an address unique the way Sulu does when it generates one (ResourceLocatorGenerator::createUnique): while the
 * address is in use, "-1", "-2", ... is appended. Sulu's data mapper does not check this for an address that arrives
 * ready-made, so the import has to do it before it writes.
 */
final class UniqueSlug
{
    private const LIMIT = 10000;

    /**
     * @param callable(string): bool $isTaken
     */
    public static function propose(string $slug, callable $isTaken): string
    {
        $candidate = $slug;
        for ($i = 1; $isTaken($candidate); ++$i) {
            if ($i > self::LIMIT) {
                throw new \RuntimeException(\sprintf('No free address found for "%s".', $slug));
            }
            $candidate = $slug.'-'.$i;
        }

        return $candidate;
    }
}
