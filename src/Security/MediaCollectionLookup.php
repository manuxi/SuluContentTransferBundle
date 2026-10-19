<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Security;

/**
 * Tells in which folder of this system an image lives, so the permissions of that folder can be checked.
 */
interface MediaCollectionLookup
{
    /**
     * @return array{id: int, system: bool}|null the folder of the image and whether it is a system folder; null if the image is unknown
     */
    public function collectionOfMedium(int $mediumId): ?array;

    /**
     * @return array{id: int, system: bool}|null the folder with this id; null if there is none
     */
    public function collection(int $collectionId): ?array;
}
