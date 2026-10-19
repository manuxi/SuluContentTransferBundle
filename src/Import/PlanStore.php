<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;

/**
 * Keeps an import plan as plan.json in the directory of the unpacked package (work_dir/<token>/), so a later step can
 * pick up the analysis by its token. A plan is valid for 24 hours; older token directories are removed whenever
 * a new analysis starts.
 */
class PlanStore
{
    public const FILE_NAME = 'plan.json';

    public function __construct(private readonly WorkDirectory $workDirectory)
    {
    }

    public function save(ImportPlan $plan): void
    {
        $file = $this->workDirectory->path($plan->token).\DIRECTORY_SEPARATOR.self::FILE_NAME;
        if (false === @file_put_contents($file, Json::encode($plan->toArray()))) {
            throw new ContentTransferException('The import plan could not be written.', 'plan.not_writable');
        }
    }

    /**
     * @throws ContentTransferException when the token is unknown, the plan is expired or unreadable
     */
    public function load(string $token, ?\DateTimeImmutable $now = null): ImportPlan
    {
        if (!WorkDirectory::isValidToken($token)) {
            throw new ContentTransferException('The import token is not valid.', 'plan.token_invalid');
        }

        $file = $this->workDirectory->path($token).\DIRECTORY_SEPARATOR.self::FILE_NAME;
        $json = is_file($file) ? @file_get_contents($file) : false;
        if (false === $json) {
            throw new ContentTransferException('The import plan does not exist (any more). Please analyze the package again.', 'plan.missing');
        }

        $plan = ImportPlan::fromArray(Json::decode($json, 'import plan'));
        if ($plan->isExpired($now)) {
            $this->workDirectory->remove($token);

            throw new ContentTransferException('The import plan has expired. Please analyze the package again.', 'plan.expired');
        }

        return $plan;
    }

    /**
     * Removes the directories of analyses that are older than a plan lives.
     *
     * @return int number of removed directories
     */
    public function purgeExpired(?int $now = null): int
    {
        return $this->workDirectory->purgeOlderThan(ImportPlan::LIFETIME_SECONDS, $now);
    }

    public function remove(string $token): void
    {
        $this->workDirectory->remove($token);
    }
}
