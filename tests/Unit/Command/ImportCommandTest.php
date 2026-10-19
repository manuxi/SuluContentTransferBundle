<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Command;

use Manuxi\SuluContentTransferBundle\Command\ImportCommand;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Import\ArticlePlanner;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\ImportExecutor;
use Manuxi\SuluContentTransferBundle\Import\MediaPlanner;
use Manuxi\SuluContentTransferBundle\Import\PlanStore;
use Manuxi\SuluContentTransferBundle\Import\Rewrite\ReferenceRewriter;
use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeArticleTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeArticleWriter;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeImportSupport;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMappings;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMediaTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMediaWriter;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeReferenceTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeSystemTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\WriteLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCommandTest extends TemporaryDirectoryTestCase
{
    private string $file;
    private WriteLog $log;

    private function tester(): CommandTester
    {
        $workDirectory = new WorkDirectory($this->workDir);
        $this->file = $this->directory.'/package.sulu-transfer.zip';
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);
        (new PackageWriter(new PackageLimits(), $workDirectory))->writeToFile($package, $this->file, $mediaFiles);

        $articles = new FakeArticleTarget();
        $references = new FakeReferenceTarget();
        $mappings = new FakeMappings();
        $reader = new PackageReader(new PackageLimits(), $workDirectory);
        $store = new PlanStore($workDirectory);
        $analyzer = new ImportAnalyzer(
            $reader,
            $store,
            new InstanceIdentity('secret', 'target-instance-01', 'target.example.org'),
            $articles,
            $references,
            new ArticlePlanner($articles, new FakeSystemTarget(), $references, $mappings),
            new MediaPlanner(new FakeMediaTarget(), $mappings),
            $mappings,
        );

        $this->log = new WriteLog();
        $support = new FakeImportSupport($this->log);
        $executor = new ImportExecutor(
            $reader,
            $store,
            $analyzer,
            $support,
            $support,
            new FakeMediaWriter($this->log),
            new FakeArticleWriter($this->log, $articles),
            $support,
            $articles,
            $references,
            $mappings,
            new ReferenceRewriter(),
            $support,
        );

        return new CommandTester(new ImportCommand($analyzer, $executor));
    }

    public function testDryRunPrintsThePlanAsTables(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file, '--dry-run' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        foreach (['Articles', 'Images', 'Will be created', 'Notices', PackageFixtures::ARTICLE_1, 'Erste Schritte', 'create', 'Tutorials > Einsteiger', 'Bild äö.jpg', 'Nothing was written'] as $text) {
            $this->assertStringContainsString($text, $display);
        }
        $this->assertStringContainsString('reference.removed', $display);
        $this->assertSame([], $this->log->lines);
    }

    public function testWithoutUserItRefusesToWrite(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('--user', $tester->getDisplay());
        $this->assertSame(0, self::countFiles($this->workDir), 'nothing was even unpacked');
        $this->assertSame([], $this->log->lines);
    }

    public function testImportsWithTheUserAndPrintsTheReport(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file, '--user' => 'editor']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status, $display);
        foreach (['Import report', 'created', PackageFixtures::ARTICLE_1, 'Created', 'Tutorials > Einsteiger', 'Bild äö.jpg', '/admin/#/de/articles/'.PackageFixtures::ARTICLE_1, 'The import finished'] as $text) {
            $this->assertStringContainsString($text, $display);
        }
        $this->assertNotSame([], $this->log->of('create'));
        $this->assertSame([], $this->log->of('publish'));
        $this->assertSame(0, self::countFiles($this->workDir), 'the unpacked package is removed');
    }

    public function testKeepStatusActionAndLocaleOptions(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'file' => $this->file,
            '--user' => 'editor',
            '--keep-status' => true,
            '--locale' => ['en'],
            '--action' => [PackageFixtures::ARTICLE_1.':copy'],
        ]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertCount(1, $this->log->of('create'));
        $this->assertNotSame(PackageFixtures::ARTICLE_1, $this->log->of('create')[0][1], 'a copy has a new UUID');
        $this->assertSame('en', $this->log->of('create')[0][2]['locale']);
        $this->assertCount(1, $this->log->of('publish'));
    }

    public function testUnknownUserFailsAndCleansUp(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file, '--user' => 'nobody']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('There is no user "nobody"', $tester->getDisplay());
        $this->assertSame([], $this->log->lines);
        $this->assertSame(0, self::countFiles($this->workDir));
    }

    public function testMalformedActionIsRefused(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file, '--user' => 'editor', '--action' => ['not-a-uuid:copy']]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('not a valid --action', $tester->getDisplay());
        $this->assertSame([], $this->log->lines);
    }

    public function testFailedArticleGivesAFailureStatus(): void
    {
        $tester = $this->tester();
        // refuse the one action the plan does not allow
        $status = $tester->execute(['file' => $this->file, '--user' => 'editor', '--action' => [PackageFixtures::ARTICLE_1.':update']]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('not allowed', $tester->getDisplay());
    }

    public function testJsonOutputIsThePlan(): void
    {
        $tester = $this->tester();

        $tester->execute(['file' => $this->file, '--dry-run' => true, '--json' => true]);
        $plan = Json::decode($tester->getDisplay());

        $this->assertSame(1, $plan['planVersion']);
        $this->assertSame(PackageFixtures::ARTICLE_1, $plan['articles'][0]['uuid']);
        $this->assertSame('create', $plan['articles'][0]['defaultAction']);
    }

    public function testJsonOutputOfAnImportIsTheReport(): void
    {
        $tester = $this->tester();

        $tester->execute(['file' => $this->file, '--user' => 'editor', '--json' => true]);
        $report = Json::decode($tester->getDisplay());

        $this->assertSame('created', $report['articles'][0]['status']);
        $this->assertSame(PackageFixtures::ARTICLE_1, $report['articles'][0]['targetUuid']);
    }

    public function testUnreadablePackageGivesAnErrorMessage(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->directory.'/missing.zip', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }
}
