<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Rewrite;

use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;

/**
 * The rewrite table of an import run: the resolutions of the plan, completed with what the run created (images,
 * categories, tags) and with the UUIDs the articles of the package get on this system.
 */
final class PlanRewriteMap implements RewriteMap
{
    /**
     * @param array<string, int>         $created        id on this system of everything the run created, by the "ref" of the
     *                                                   plan ("media:<id>", "tag:<name>", "key:<key>", "path:<hash>")
     * @param array<string, string|null> $articleTargets UUID on this system per article of the package (null: the article is
     *                                                   not available here, references to it are removed)
     */
    public function __construct(
        private readonly ImportPlan $plan,
        private array $created = [],
        private array $articleTargets = [],
    ) {
    }

    public function addCreated(string $ref, int $id): void
    {
        $this->created[$ref] = $id;
    }

    public function setArticleTarget(string $sourceUuid, ?string $targetUuid): void
    {
        $this->articleTargets[$sourceUuid] = $targetUuid;
    }

    public function resolve(string $kind, int|string $sourceId): Rewrite
    {
        if ('tags' === $kind && \is_string($sourceId)) {
            // tag filters of a smart content hold names; the tag itself is made sure to exist by the run
            return Rewrite::keep();
        }

        $resolution = $this->plan->resolution($kind, $sourceId);
        if (null === $resolution) {
            return Rewrite::keep();
        }

        return match ($resolution->status) {
            PlanResolution::MAPPED => null !== $resolution->targetId ? Rewrite::replace($resolution->targetId) : Rewrite::remove(),
            PlanResolution::PLANNED => $this->planned($resolution),
            PlanResolution::PACKAGE => $this->article((string) $sourceId),
            PlanResolution::UNCHECKED => Rewrite::keep(),
            default => Rewrite::remove(),
        };
    }

    private function planned(PlanResolution $resolution): Rewrite
    {
        $id = null !== $resolution->ref ? ($this->created[$resolution->ref] ?? null) : null;

        return null !== $id ? Rewrite::replace($id) : Rewrite::remove();
    }

    private function article(string $uuid): Rewrite
    {
        $target = $this->articleTargets[$uuid] ?? null;

        return null !== $target ? Rewrite::replace($target) : Rewrite::remove();
    }
}
