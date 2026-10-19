<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Write;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Manuxi\SuluContentTransferBundle\Import\Write\DoctrineTransaction;
use PHPUnit\Framework\TestCase;

final class DoctrineTransactionTest extends TestCase
{
    public function testCommitsAfterTheWorkAndFlushes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');
        $connection->expects($this->never())->method('rollBack');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects($this->once())->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('resetManager');

        $result = (new DoctrineTransaction($entityManager, $registry))->run(static fn (): string => 'done');

        $this->assertSame('done', $result);
    }

    public function testRollsBackClearsAndRethrowsWhenTheWorkFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('commit');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->expects($this->once())->method('clear');
        $entityManager->expects($this->never())->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('resetManager');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        (new DoctrineTransaction($entityManager, $registry))->run(static function (): void {
            throw new \RuntimeException('boom');
        });
    }

    public function testReplacesTheEntityManagerThatAFailedFlushClosed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('rollBack')->willThrowException(new \RuntimeException('no transaction is active any more'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('isOpen')->willReturn(false);
        $entityManager->expects($this->never())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->once())->method('resetManager');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the flush failed');

        (new DoctrineTransaction($entityManager, $registry))->run(static function (): void {
            throw new \LogicException('the flush failed');
        });
    }
}
