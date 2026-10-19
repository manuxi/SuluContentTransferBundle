<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

/**
 * Writes articles the way the admin does (through Sulu's messages), one language at a time.
 */
interface ArticleWriter
{
    /**
     * Creates an article with the given UUID and the content of its first language ("locale" in the data).
     *
     * @param array<string, mixed> $data
     */
    public function create(string $uuid, array $data): void;

    /**
     * Writes the content of one language ("locale" in the data) as a draft; other languages stay as they are.
     *
     * @param array<string, mixed> $data
     */
    public function modify(string $uuid, array $data): void;

    public function publish(string $uuid, string $locale): void;
}
