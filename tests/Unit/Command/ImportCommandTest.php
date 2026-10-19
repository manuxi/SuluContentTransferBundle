<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Command;

use Manuxi\SuluContentTransferBundle\Command\ImportCommand;
use Manuxi\SuluContentTransferBundle\Format\Json;
use Manuxi\SuluContentTransferBundle\Import\ArticlePlanner;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\MediaPlanner;
use Manuxi\SuluContentTransferBundle\Import\PlanStore;
use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\Tests\Support\PackageFixtures;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeArticleTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMappings;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeMediaTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeReferenceTarget;
use Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake\FakeSystemTarget;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCommandTest extends TemporaryDirectoryTestCase
{
    private string $file;

    private function tester(): CommandTester
    {
        $workDirectory = new WorkDirectory($this->workDir);
        $this->file = $this->directory.'/package.sulu-transfer.zip';
        ['package' => $package, 'mediaFiles' => $mediaFiles] = PackageFixtures::package($this->directory);
        (new PackageWriter(new PackageLimits(), $workDirectory))->writeToFile($package, $this->file, $mediaFiles);

        $articles = new FakeArticleTarget();
        $references = new FakeReferenceTarget();
        $mappings = new FakeMappings();
        $analyzer = new ImportAnalyzer(
            new PackageReader(new PackageLimits(), $workDirectory),
            new PlanStore($workDirectory),
            new InstanceIdentity('secret', 'target-instance-01', 'target.example.org'),
            $articles,
            $references,
            new ArticlePlanner($articles, new FakeSystemTarget(), $references, $mappings),
            new MediaPlanner(new FakeMediaTarget(), $mappings),
            $mappings,
        );

        return new CommandTester(new ImportCommand($analyzer));
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
    }

    public function testWithoutDryRunItRefusesToWrite(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('not available yet', $tester->getDisplay());
        $this->assertSame(0, self::countFiles($this->workDir), 'nothing was even unpacked');
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

    public function testUnreadablePackageGivesAnErrorMessage(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->directory.'/missing.zip', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }
}
