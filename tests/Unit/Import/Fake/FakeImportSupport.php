<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Import\Write\AdminLinks;
use Manuxi\SuluContentTransferBundle\Import\Write\ImportUserContext;
use Manuxi\SuluContentTransferBundle\Import\Write\TaxonomyWriter;
use Manuxi\SuluContentTransferBundle\Import\Write\Transaction;

/**
 * Taxonomy writer, user context, transaction and admin links of the tests in one object.
 */
final class FakeImportSupport implements TaxonomyWriter, ImportUserContext, Transaction, AdminLinks
{
    public int $nextCategoryId = 500;
    public int $nextTagId = 600;

    /** @var list<string> usernames that exist */
    public array $users = ['editor'];

    public ?string $username = null;
    public int $refreshed = 0;
    public int $transactions = 0;
    public int $rollbacks = 0;

    public function __construct(private readonly WriteLog $log)
    {
    }

    public function createCategory(?string $key, array $names, ?int $parentId): int
    {
        $id = $this->nextCategoryId++;
        $this->log->add('category', (string) $key, ['id' => $id, 'names' => $names, 'parent' => $parentId], 'category '.$key.' parent='.($parentId ?? '-'));

        return $id;
    }

    public function createTag(string $name): int
    {
        $id = $this->nextTagId++;
        $this->log->add('tag', $name, ['id' => $id], 'tag '.$name);

        return $id;
    }

    public function activate(string $username): void
    {
        if (!\in_array($username, $this->users, true)) {
            throw new ContentTransferException(\sprintf('There is no user "%s".', $username), 'import.user_unknown');
        }
        $this->username = $username;
    }

    public function refresh(): void
    {
        ++$this->refreshed;
    }

    public function userId(): int
    {
        return 1;
    }

    public function run(callable $work): mixed
    {
        ++$this->transactions;
        $before = \count($this->log->calls);
        $beforeLines = \count($this->log->lines);
        try {
            return $work();
        } catch (\Throwable $exception) {
            ++$this->rollbacks;
            // what the failed unit wrote is rolled back: it does not count
            $this->log->calls = \array_slice($this->log->calls, 0, $before);
            $this->log->lines = \array_slice($this->log->lines, 0, $beforeLines);

            throw $exception;
        }
    }

    public function article(string $uuid, string $locale, ?string $templateKey): ?string
    {
        return '/admin/#/'.$locale.'/articles/'.$uuid;
    }
}
