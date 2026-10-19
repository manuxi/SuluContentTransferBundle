<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

/**
 * Runs the writes of one article in one database transaction: all or nothing.
 */
interface Transaction
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws \Throwable whatever the work throws, after the transaction was rolled back
     */
    public function run(callable $work): mixed;
}
