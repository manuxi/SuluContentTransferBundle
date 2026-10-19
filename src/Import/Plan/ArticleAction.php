<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

/**
 * What an import can do with one article of the package.
 */
final class ArticleAction
{
    /** Create the article with the UUID it has in the package. */
    public const CREATE = 'create';
    /** Write the package content into the article that was matched on this system. */
    public const UPDATE = 'update';
    /** Create a new article with a new UUID, whatever exists on this system. */
    public const COPY = 'copy';
    /** Leave the article alone. */
    public const SKIP = 'skip';

    public const ALL = [self::CREATE, self::UPDATE, self::COPY, self::SKIP];

    private function __construct()
    {
    }
}
