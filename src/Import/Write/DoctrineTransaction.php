<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * One database transaction per unit of work. Sulu's own flushes happen inside it (nested), the commit only happens when
 * the whole unit succeeded. After a failure the entity manager is cleared, or replaced if the failed flush closed it.
 */
class DoctrineTransaction implements Transaction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function run(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            try {
                $connection->rollBack();
            } catch (\Throwable) { // @phpstan-ignore-line
                // @ignoreException the transaction is gone already (the connection was closed); nothing is left to roll back
            }
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            } else {
                $this->registry->resetManager();
            }

            throw $exception;
        }
    }
}
