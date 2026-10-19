<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Command;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\ImportExecutor;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\Notice;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanCategory;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Import\Report\ArticleResult;
use Manuxi\SuluContentTransferBundle\Import\Report\ImportReport;
use Manuxi\SuluContentTransferBundle\Import\Report\MediaResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sulu:content-transfer:import',
    description: 'Imports a transfer package (.sulu-transfer.zip), or with --dry-run shows what an import would do.',
)]
class ImportCommand extends Command
{
    public function __construct(
        private readonly ImportAnalyzer $analyzer,
        private readonly ImportExecutor $executor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the package')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only analyze the package and print the plan; nothing is written')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Username of the Sulu user who imports (creator and changer of everything that is written; required to import)')
            ->addOption('action', 'a', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Action for one article: <uuid>:create|update|copy|skip (default: the action the plan suggests)')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Import only this language (repeat for more; default: all languages the plan accepts)')
            ->addOption('keep-status', null, InputOption::VALUE_NONE, 'Publish the languages that were published on the source (default: everything is imported as a draft)')
            ->addOption('overwrite-media-meta', null, InputOption::VALUE_NONE, 'Overwrite titles, descriptions and copyright of images that already exist here')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the plan (--dry-run) or the report as JSON instead of tables')
            ->setHelp(<<<'TXT'
Analyzes a package without writing anything:

  <info>php bin/adminconsole sulu:content-transfer:import tutorials.sulu-transfer.zip --dry-run</info>

Imports it (everything becomes a draft; the suggested action is used for every article):

  <info>php bin/adminconsole sulu:content-transfer:import tutorials.sulu-transfer.zip --user=editor</info>

Publishes what was published on the source, and imports one article as a copy:

  <info>php bin/adminconsole sulu:content-transfer:import tutorials.sulu-transfer.zip --user=editor --keep-status --action=0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8:copy</info>
TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $username = $input->getOption('user');

        if (!$dryRun && (!\is_string($username) || '' === $username)) {
            $io->error('Please name the user who imports with --user=<username>, or use --dry-run to only analyze the package.');

            return Command::FAILURE;
        }

        try {
            $decisions = $this->decisions($input);
            $plan = $this->analyzer->analyze((string) $input->getArgument('file'));
        } catch (ContentTransferException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            if ($input->getOption('json')) {
                $output->write(Json::encode($plan->toArray()));
            } else {
                $this->print($io, $plan);
                $io->newLine();
                $io->success('The package was analyzed. Nothing was written.');
            }

            return Command::SUCCESS;
        }

        if (!$input->getOption('json')) {
            $this->print($io, $plan);
        }

        try {
            $report = $this->executor->execute($plan->token, $decisions, (string) $username);
        } catch (ContentTransferException $exception) {
            $this->executor->discard($plan->token);
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->write(Json::encode($report->toArray()));
        } else {
            $this->printReport($io, $report);
        }

        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @throws ContentTransferException
     */
    private function decisions(InputInterface $input): ImportDecisions
    {
        $actions = [];
        foreach ((array) $input->getOption('action') as $spec) {
            if (1 !== preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}):(create|update|copy|skip)\z/', (string) $spec, $match)) {
                throw new ContentTransferException(\sprintf('"%s" is not a valid --action. Expected <uuid>:create|update|copy|skip.', (string) $spec), 'import.action_invalid');
            }
            $actions[$match[1]] = $match[2];
        }
        $locales = array_values(array_filter((array) $input->getOption('locale'), static fn ($locale): bool => \is_string($locale) && '' !== $locale));

        return new ImportDecisions(
            $actions,
            [] === $locales ? null : $locales,
            (bool) $input->getOption('keep-status'),
            (bool) $input->getOption('overwrite-media-meta'),
        );
    }

    private function print(SymfonyStyle $io, ImportPlan $plan): void
    {
        $io->definitionList(
            ['Package from' => \sprintf('%s (%s), Sulu %s, created %s', $plan->source->label, $plan->source->instanceId, $plan->source->suluVersion, $plan->packageCreatedAt->format('Y-m-d H:i'))],
            ['This system' => \sprintf('%s (%s)', $plan->targetLabel, $plan->targetInstanceId)],
            ['Same system' => $plan->sameInstance ? 'yes: only "copy" is allowed' : 'no'],
            ['Token' => $plan->token.' (valid until '.$plan->expiresAt->format('Y-m-d H:i').')'],
        );

        $rows = [];
        foreach ($plan->articles as $article) {
            $rows[] = [
                self::cut($article->title(), 40),
                $article->uuid,
                $this->stateText($article),
                $article->defaultAction,
                implode(', ', $article->allowedActions),
                $this->localesText($article),
            ];
        }
        $io->section('Articles');
        $io->table(['Title', 'UUID', 'State', 'Action', 'Allowed', 'Languages'], $rows);

        $urlRows = [];
        foreach ($plan->articles as $article) {
            $urls = ('copy' === $article->defaultAction) ? $article->copyUrls : $article->urls;
            foreach ($urls as $locale => $url) {
                $urlRows[] = [$article->uuid, $locale, $url->requested ?? '-', $url->proposed ?? '-', $url->isTaken() ? 'taken by '.($url->takenBy ?? '?') : ''];
            }
        }
        if ([] !== $urlRows) {
            $io->section('Addresses (for the suggested action)');
            $io->table(['Article', 'Language', 'Requested', 'Gets', 'Note'], $urlRows);
        }

        $io->section('Images');
        if ([] === $plan->media) {
            $io->writeln('No images.');
        } else {
            $io->table(
                ['Id', 'File', 'Size', 'In package', 'Action', 'Target', 'Via', 'Folder'],
                array_map(static fn (PlanMedium $medium): array => [
                    $medium->sourceId,
                    $medium->filename ?? '?',
                    null === $medium->size ? '?' : (string) $medium->size,
                    $medium->hasFile ? 'file' : 'name only',
                    $medium->action,
                    $medium->targetId ?? '-',
                    $medium->reason ?? '-',
                    null === $medium->collection ? '-' : ('system' === $medium->collection['type'] ? 'system: '.($medium->collection['key'] ?? '') : 'collection '.($medium->collection['id'] ?? '')),
                ], $plan->media),
            );
        }

        if ([] !== $plan->categories || [] !== $plan->tags) {
            $io->section('Will be created');
            $created = array_map(fn (PlanCategory $category): array => ['category', $this->categoryPath($plan, $category), $category->key ?? '-'], $plan->categories);
            foreach ($plan->tags as $tag) {
                $created[] = ['tag', $tag, '-'];
            }
            $io->table(['Kind', 'Name', 'Key'], $created);
        }

        $io->section('Notices');
        if ([] === $plan->notices) {
            $io->writeln('None.');
        }
        foreach ($plan->notices as $notice) {
            $line = \sprintf('[%s] %s%s%s', $notice->code, null !== $notice->articleUuid ? $notice->articleUuid.' ' : '', null !== $notice->locale ? '('.$notice->locale.') ' : '', $notice->message);
            match ($notice->level) {
                Notice::ERROR => $io->writeln('<error> ERROR </error> '.$line),
                Notice::WARNING => $io->writeln('<comment> WARNING </comment> '.$line),
                default => $io->writeln('<info> INFO </info> '.$line),
            };
        }
    }

    private function printReport(SymfonyStyle $io, ImportReport $report): void
    {
        $io->title('Import report');

        $rows = [];
        foreach ($report->articles as $article) {
            $rows[] = [
                self::cut($article->title, 36),
                $article->sourceUuid,
                $article->targetUuid ?? '-',
                $article->action,
                $article->status,
                implode(', ', $article->locales) ?: '-',
                implode(', ', $article->published) ?: '-',
            ];
        }
        $io->section('Articles');
        $io->table(['Title', 'Source UUID', 'Target UUID', 'Action', 'Result', 'Languages written', 'Published'], $rows);

        foreach ($report->articles as $article) {
            foreach ($article->adminLinks as $locale => $link) {
                $io->writeln(\sprintf('  %s (%s): %s', self::cut($article->title, 40), $locale, $link));
            }
            foreach ($article->skippedLocales as $locale => $reason) {
                $io->writeln(\sprintf('  <comment>%s (%s) skipped: %s</comment>', self::cut($article->title, 40), $locale, $reason));
            }
            foreach ($article->renamedUrls as $locale => $rename) {
                $io->writeln(\sprintf('  <comment>%s (%s) address %s was taken, now %s</comment>', self::cut($article->title, 40), $locale, $rename['from'], $rename['to']));
            }
            if ($article->failed()) {
                $io->writeln(\sprintf('  <error> FAILED </error> %s: %s', self::cut($article->title, 40), $article->message ?? ''));
            } elseif (ArticleResult::SKIPPED === $article->status && null !== $article->message) {
                $io->writeln(\sprintf('  %s: %s', self::cut($article->title, 40), $article->message));
            }
        }

        $removed = [];
        foreach ($report->articles as $article) {
            foreach ($article->removed as $reference) {
                $removed[] = [self::cut($article->title, 30), $reference->locale, $reference->kind, (string) $reference->id, $reference->path];
            }
        }
        if ([] !== $removed) {
            $io->section('References that were removed (target not available here)');
            $io->table(['Article', 'Language', 'Kind', 'Id', 'Place'], $removed);
        }

        if ([] !== $report->categories || [] !== $report->tags) {
            $io->section('Created');
            $created = array_map(static fn (array $category): array => ['category', $category['path'], (string) $category['id'], $category['key'] ?? '-'], $report->categories);
            foreach ($report->tags as $tag) {
                $created[] = ['tag', $tag['name'], (string) $tag['id'], '-'];
            }
            $io->table(['Kind', 'Name', 'Id', 'Key'], $created);
        }

        $io->section('Images');
        if ([] === $report->media) {
            $io->writeln('No images.');
        } else {
            $io->table(
                ['Id (source)', 'File', 'Result', 'Id (here)', 'Texts updated', 'Note'],
                array_map(static fn (MediaResult $medium): array => [
                    $medium->sourceId,
                    $medium->filename ?? '?',
                    $medium->outcome,
                    $medium->targetId ?? '-',
                    $medium->textsUpdated ? 'yes' : '-',
                    implode(' ', $medium->warnings) ?: ($medium->message ?? ''),
                ], $report->media),
            );
        }

        foreach ($report->errors as $error) {
            $io->writeln('<error> ERROR </error> '.$error);
        }

        $io->newLine();
        if ($report->hasFailures()) {
            $io->error('The import finished with errors. Articles that failed were not changed; the others were imported.');
        } else {
            $io->success('The import finished.');
        }
    }

    private function stateText(PlanArticle $article): string
    {
        return match ($article->state) {
            'new' => 'new',
            'unchanged' => 'exists (same UUID'.('mapping' === $article->matchedBy ? ', earlier import' : '').'), unchanged',
            'changed' => 'changed here'.(null !== $article->changedAt ? ' on '.$article->changedAt->format('Y-m-d H:i') : '').(null !== $article->changedBy ? ' by '.$article->changedBy : ''),
            default => 'cannot be imported',
        };
    }

    private function localesText(PlanArticle $article): string
    {
        $parts = [];
        foreach ($article->locales as $locale) {
            $parts[] = $locale->import ? $locale->locale : $locale->locale.' (skipped: '.$locale->reason.')';
        }

        return implode(', ', $parts);
    }

    private function categoryPath(ImportPlan $plan, PlanCategory $category): string
    {
        $names = [$category->label()];
        $seen = [];
        for ($parent = $category->parentRef; null !== $parent && !isset($seen[$parent]); ) {
            $seen[$parent] = true;
            $found = null;
            foreach ($plan->categories as $candidate) {
                if ($candidate->ref === $parent) {
                    $found = $candidate;
                }
            }
            if (null === $found) {
                break;
            }
            array_unshift($names, $found->label());
            $parent = $found->parentRef;
        }

        return implode(' > ', $names);
    }

    private static function cut(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
