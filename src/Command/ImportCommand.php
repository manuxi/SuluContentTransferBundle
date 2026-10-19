<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Command;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\Plan\Notice;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanArticle;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanCategory;
use Manuxi\SuluContentTransferBundle\Import\Plan\PlanMedium;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sulu:content-transfer:import',
    description: 'Analyzes a transfer package (.sulu-transfer.zip) and shows what an import would do (--dry-run).',
)]
class ImportCommand extends Command
{
    public function __construct(private readonly ImportAnalyzer $analyzer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the package')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only analyze the package and print the plan; nothing is written')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the plan as JSON instead of tables')
            ->setHelp(<<<'TXT'
Analyzes a package without writing anything. Example:

  <info>php bin/adminconsole sulu:content-transfer:import tutorials.sulu-transfer.zip --dry-run</info>
TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('dry-run')) {
            $io->error('Executing the import is not available yet (it follows in a later release). Use --dry-run to analyze the package.');

            return Command::FAILURE;
        }

        try {
            $plan = $this->analyzer->analyze((string) $input->getArgument('file'));
        } catch (ContentTransferException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->write(Json::encode($plan->toArray()));

            return Command::SUCCESS;
        }

        $this->print($io, $plan);

        return Command::SUCCESS;
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

        $io->newLine();
        $io->success('The package was analyzed. Nothing was written.');
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
