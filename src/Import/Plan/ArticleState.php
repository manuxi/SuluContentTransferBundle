<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

/**
 * State of an article of the package on this system.
 */
final class ArticleState
{
    /** No article of this origin exists here yet. */
    public const NEW = 'new';
    /** The article exists here and nothing was changed here since the package state (or the last import). */
    public const UNCHANGED = 'unchanged';
    /** The article exists here and was changed here after the package state (or the last import). */
    public const CHANGED = 'changed';
    /** The article cannot be imported (unknown template, no importable language). */
    public const BLOCKED = 'blocked';

    public const ALL = [self::NEW, self::UNCHANGED, self::CHANGED, self::BLOCKED];

    private function __construct()
    {
    }
}
