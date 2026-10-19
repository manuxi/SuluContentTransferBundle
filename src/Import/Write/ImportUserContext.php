<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

/**
 * The user the import writes as: creator and changer of everything it creates (the console has no logged in user).
 */
interface ImportUserContext
{
    /**
     * Makes the user known to the security layer.
     *
     * @throws \Manuxi\SuluContentTransferBundle\Exception\ContentTransferException when there is no such user
     */
    public function activate(string $username): void;

    /**
     * Brings the user back after the entity manager was cleared (it detaches the user held by the security token).
     */
    public function refresh(): void;

    public function userId(): int;
}
