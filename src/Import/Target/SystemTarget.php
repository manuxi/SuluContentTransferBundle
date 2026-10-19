<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

/**
 * The webspaces and languages of this system.
 */
interface SystemTarget
{
    /**
     * @return list<string> keys of the webspaces
     */
    public function webspaceKeys(): array;

    /**
     * @return list<string> languages configured for a webspace
     */
    public function localesOf(string $webspace): array;

    /**
     * Webspace that articles get when the one named in the package does not exist here.
     */
    public function defaultWebspace(): ?string;
}
