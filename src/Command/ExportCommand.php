<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Command;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Export\ArticleExporter;
use Manuxi\SuluContentTransferBundle\Export\ExportedArticle;
use Manuxi\SuluContentTransferBundle\Export\ExportRequest;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sulu:content-transfer:export',
    description: 'Exports articles with their images into a transfer package (.sulu-transfer.zip).',
)]
class ExportCommand extends Command
{
    public function __construct(
        private readonly ArticleExporter $exporter,
        private readonly PackageWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('uuid', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'UUID of an article (several allowed)')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Language to export (repeatable). Default: every language the articles have')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Version to export: "live" (published, falls back to the draft of a language that is not published) or "draft"', ExportRequest::STAGE_LIVE)
            ->addOption('no-media-files', null, InputOption::VALUE_NONE, 'Leave the image files out; the package then only names the images')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Path of the package. Default: <date>-<first title>.sulu-transfer.zip in the current directory')
            ->setHelp(<<<'TXT'
The package can be imported on another system with the same templates. Example:

  <info>php bin/adminconsole sulu:content-transfer:export 4b1c0b3a-... --locale=de --locale=en --output=tutorials.sulu-transfer.zip</info>
TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $uuids */
        $uuids = array_values((array) $input->getArgument('uuid'));
        /** @var list<string> $locales */
        $locales = array_values((array) $input->getOption('locale'));

        try {
            $request = new ExportRequest($uuids, $locales, (string) $input->getOption('stage'), !$input->getOption('no-media-files'));
            $result = $this->exporter->export($request);
        } catch (ContentTransferException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        try {
            $target = $this->targetFile($input->getOption('output'), $result->articles);
            $this->writer->writeToFile($result->package, $target, $result->mediaFiles);
        } catch (ContentTransferException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->exporter->release($result);
        }

        $this->printReport($io, $result->articles);

        $imageNote = $request->includeMediaFiles ? 'files in the package' : 'only named, files not included';
        $io->definitionList(
            ['Images' => \sprintf('%d (%s, %s)', $result->mediaCount(), self::formatBytes($result->mediaBytes()), $imageNote)],
            ['References' => [] === $result->referenceCount ? 'none' : implode(', ', array_map(static fn (string $kind, int $count): string => $kind . ' ' . $count, array_keys($result->referenceCount), $result->referenceCount))],
            ['Package' => $target],
            ['Package size' => self::formatBytes((int) filesize($target))],
        );

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }

        $io->success('The package was written.');

        return Command::SUCCESS;
    }

    /**
     * @param list<ExportedArticle> $articles
     */
    private function printReport(SymfonyStyle $io, array $articles): void
    {
        $locales = [];
        foreach ($articles as $article) {
            foreach (array_keys($article->locales) as $locale) {
                $locales[$locale] = true;
            }
        }
        $locales = array_keys($locales);
        sort($locales, \SORT_STRING);

        $rows = [];
        foreach ($articles as $article) {
            $row = [$article->title, $article->uuid];
            foreach ($locales as $locale) {
                $row[] = $article->locales[$locale] ?? '-';
            }
            $rows[] = $row;
        }

        $io->table(['Article', 'UUID', ...$locales], $rows);
    }

    /**
     * @param list<ExportedArticle> $articles
     */
    private function targetFile(mixed $option, array $articles): string
    {
        if (\is_string($option) && '' !== $option) {
            return $option;
        }

        $title = '';
        foreach ($articles as $article) {
            if ('' !== $article->title) {
                $title = $article->title;

                break;
            }
        }

        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', self::transliterate($title)), '-'));
        $slug = '' === $slug ? 'articles' : substr($slug, 0, 60);

        return (getcwd() ?: '.') . \DIRECTORY_SEPARATOR . date('Y-m-d') . '-' . $slug . '.sulu-transfer.zip';
    }

    private static function transliterate(string $text): string
    {
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);

        return false === $converted ? $text : $converted;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        return round($bytes / 1024 / 1024, 1) . ' MiB';
    }
}
