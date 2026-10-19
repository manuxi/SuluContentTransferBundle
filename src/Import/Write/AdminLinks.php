<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

/**
 * Where an article can be opened in the admin.
 */
interface AdminLinks
{
    /**
     * @return ?string path with fragment (e.g. "/admin/#/...") or null if it cannot be worked out
     */
    public function article(string $uuid, string $locale, ?string $templateKey): ?string;
}
