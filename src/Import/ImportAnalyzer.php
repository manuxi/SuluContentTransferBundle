<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import;

use Manuxi\SuluContentTransferBundle\Format\ManifestItem;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\Notice;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanResolution;
use Manuxi\SuluContentTransferBundle\Import\Target\ArticleTarget;
use Manuxi\SuluContentTransferBundle\Import\Target\ReferenceTarget;
use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use Manuxi\SuluContentTransferBundle\Package\ExtractedPackage;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Repository\TransferMappings;

/**
 * Step 1 of an import: reads a package and works out what an import would do on this system, without writing
 * anything (the unpacked package and plan.json under the work directory are the only things that are created).
 * The result is the ImportPlan, which is stored under a token and shown by the console (--dry-run) and the admin.
 */
class ImportAnalyzer
{
    public function __construct(
        private readonly PackageReader $reader,
        private readonly PlanStore $planStore,
        private readonly InstanceIdentity $identity,
        private readonly ArticleTarget $articleTarget,
        private readonly ReferenceTarget $referenceTarget,
        private readonly ArticlePlanner $articlePlanner,
        private readonly MediaPlanner $mediaPlanner,
        private readonly TransferMappings $mappings,
    ) {
    }

    /**
     * @throws \Manuxi\SuluContentTransferBundle\Exception\ContentTransferException when the package cannot be read
     */
    public function analyze(string $zipFile, ?\DateTimeImmutable $now = null): ImportPlan
    {
        $this->planStore->purgeExpired(null !== $now ? $now->getTimestamp() : null);

        $extracted = $this->reader->read($zipFile);

        try {
            $plan = $this->buildPlan($extracted, $now ?? new \DateTimeImmutable());
            $this->planStore->save($plan);
        } catch (\Throwable $exception) {
            $this->planStore->remove($extracted->token);

            throw $exception;
        }

        return $plan;
    }

    private function buildPlan(ExtractedPackage $extracted, \DateTimeImmutable $now): ImportPlan
    {
        $package = $extracted->package;
        $manifest = $package->manifest;
        $sourceInstance = $manifest->source->instanceId;
        $sameInstance = $sourceInstance === $this->identity->getId();

        $notices = [];
        if ($sameInstance) {
            $notices[] = new Notice(
                Notice::WARNING,
                'source.same_instance',
                'The package was exported from this system. Its articles can only be imported as copies.',
            );
        }

        $items = [];
        foreach ($manifest->items as $item) {
            if (ManifestItem::RESOURCE_ARTICLES === $item->resourceKey) {
                $items[$item->id] = $item;
            }
        }

        $resolver = new ReferenceResolver(
            $this->referenceTarget,
            $this->mappings,
            $sourceInstance,
            $manifest->references,
            array_fill_keys(array_keys($package->articles), true),
        );
        $context = new AnalysisContext($sourceInstance, $sameInstance, $this->articleTarget->templateKeys(), $resolver);

        // 1. articles: match, state, languages, addresses
        $planned = [];
        foreach ($package->articles as $uuid => $entry) {
            $result = $this->articlePlanner->plan($entry, $items[$uuid], $context);
            $planned[$uuid] = $result->article;
            array_push($notices, ...$result->notices);
        }

        // 2. images used by the languages that are imported
        $usage = [];
        foreach ($package->articles as $uuid => $entry) {
            foreach ($this->importedReferences($entry->references, $planned[$uuid]) as $reference) {
                if ('media' === $reference->resourceKey) {
                    $usage[(int) $reference->id][] = $uuid;
                }
            }
        }
        $media = $this->mediaPlanner->plan($package, $sourceInstance, $usage);
        foreach (MediaPlanner::resolutions($media) as $resolution) {
            $resolver->add($resolution);
        }
        foreach ($media as $medium) {
            if (PlanMedium::NEW_VERSION === $medium->action) {
                $notices[] = new Notice(
                    Notice::INFO,
                    'media.new_version',
                    \sprintf('The image "%s" is known from an earlier import but its file changed. A new file version is added.', $medium->filename),
                    null,
                    null,
                    ['mediaId' => $medium->sourceId],
                );
            }
            if ((PlanMedium::CREATE === $medium->action || PlanMedium::NEW_VERSION === $medium->action) && isset($package->media[$medium->sourceId])) {
                foreach ($package->media[$medium->sourceId]->meta->tags as $tag) {
                    $resolver->planTag($tag);
                }
            }
        }

        // 3. everything else the articles point to, with a remark for what cannot be kept
        foreach ($package->articles as $uuid => $entry) {
            array_push($notices, ...$this->referenceNotices($uuid, $this->importedReferences($entry->references, $planned[$uuid]), $resolver, $media));
        }

        return new ImportPlan(
            $extracted->token,
            $now,
            $now->modify('+'.ImportPlan::LIFETIME_SECONDS.' seconds'),
            $manifest->source,
            $manifest->createdAt,
            $manifest->options->toArray(),
            $sameInstance,
            $this->identity->getId(),
            $this->identity->getLabel(),
            array_values($planned),
            $media,
            $resolver->categories(),
            $resolver->tags(),
            $resolver->resolutions(),
            $notices,
        );
    }

    /**
     * The references of the languages an article is imported in.
     *
     * @param list<\Manuxi\SuluContentTransferBundle\Format\ArticleReference> $references
     *
     * @return list<\Manuxi\SuluContentTransferBundle\Format\ArticleReference>
     */
    private function importedReferences(array $references, PlanArticle $article): array
    {
        $locales = array_flip($article->importLocales());

        return array_values(array_filter($references, static fn ($reference): bool => isset($locales[$reference->locale])));
    }

    /**
     * @param list<\Manuxi\SuluContentTransferBundle\Format\ArticleReference> $references
     * @param list<PlanMedium>                                                 $media
     *
     * @return list<Notice>
     */
    private function referenceNotices(string $uuid, array $references, ReferenceResolver $resolver, array $media): array
    {
        $found = [];
        foreach ($references as $reference) {
            $kind = 'tags' === $reference->resourceKey && \is_string($reference->id) ? 'tag_names' : $reference->resourceKey;
            $key = PlanResolution::key($kind, $reference->id);
            $found[$key]['kind'] = $kind;
            $found[$key]['id'] = $reference->id;
            $found[$key]['locales'][$reference->locale] = true;
            $found[$key]['places'] = ($found[$key]['places'] ?? 0) + 1;
        }
        ksort($found, \SORT_STRING);

        $mediaByIds = [];
        foreach ($media as $medium) {
            $mediaByIds[$medium->sourceId] = $medium;
        }

        $notices = [];
        foreach ($found as $info) {
            $kind = $info['kind'];
            $id = $info['id'];
            $locales = array_keys($info['locales']);
            sort($locales, \SORT_STRING);
            $where = \sprintf('%d place%s in %s', $info['places'], 1 === $info['places'] ? '' : 's', implode(', ', $locales));
            $params = ['kind' => $kind, 'id' => $id, 'places' => $info['places'], 'locales' => implode(',', $locales)];

            if ('media' === $kind) {
                $medium = $mediaByIds[(int) $id] ?? null;
                if (null === $medium || PlanMedium::MISSING === $medium->action) {
                    $name = null !== $medium?->filename ? '"'.$medium->filename.'"' : (string) $id;
                    $notices[] = new Notice(
                        Notice::WARNING,
                        'reference.removed',
                        \sprintf('The image %s is not in the package and not on this system. The reference is removed (%s).', $name, $where),
                        $uuid,
                        null,
                        $params,
                    );
                }

                continue;
            }

            $resolution = $resolver->resolve($kind, $id);
            if (PlanResolution::REMOVED === $resolution->status) {
                $notices[] = new Notice(
                    Notice::WARNING,
                    'reference.removed',
                    \sprintf('The %s was not found on this system. The reference is removed (%s).', $resolver->describe($kind, $id), $where),
                    $uuid,
                    null,
                    $params,
                );
            } elseif (PlanResolution::UNCHECKED === $resolution->status) {
                $known = \in_array($kind, ReferenceResolver::KINDS_BY_ID, true);
                $notices[] = new Notice(
                    $known ? Notice::INFO : Notice::WARNING,
                    $known ? 'reference.unchecked' : 'reference.unverifiable',
                    $known
                        ? \sprintf('The %s was assigned by its id without a check; the package cannot describe it (%s).', $resolver->describe($kind, $id), $where)
                        : \sprintf('References of the kind "%s" cannot be checked. The id %s is kept as it is (%s).', $kind, (string) $id, $where),
                    $uuid,
                    null,
                    $params,
                );
            }
        }

        return $notices;
    }
}
