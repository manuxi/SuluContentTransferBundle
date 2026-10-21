<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\DependencyInjection;

use Manuxi\SuluContentTransferBundle\Controller\Admin\ExportController;
use Manuxi\SuluContentTransferBundle\Controller\Admin\ImportController;
use Manuxi\SuluContentTransferBundle\DependencyInjection\SuluContentTransferExtension;
use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Package\PackageReader;
use Manuxi\SuluContentTransferBundle\Package\PackageWriter;
use Manuxi\SuluContentTransferBundle\Package\WorkDirectory;
use Manuxi\SuluContentTransferBundle\SuluContentTransferBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\RequestStack;

class ExtensionTest extends TestCase
{
    public function testBundleProvidesTheExtensionWithTheAliasOfTheConfiguration(): void
    {
        $extension = (new SuluContentTransferBundle())->getContainerExtension();

        $this->assertInstanceOf(SuluContentTransferExtension::class, $extension);
        $this->assertSame('sulu_content_transfer', $extension->getAlias());
    }

    public function testServicesAreWiredWithTheDefaults(): void
    {
        $container = $this->container([]);

        $this->assertSame('/project/var/content-transfer', $container->getParameter('sulu_content_transfer.work_dir'));
        $this->assertSame('dropdown', $container->getParameter('sulu_content_transfer.admin.toolbar'));
        $this->assertTrue($container->getParameter('sulu_content_transfer.admin.merge_csv_export'));
        $this->assertFalse($container->getParameter('sulu_content_transfer.admin.article_csv_export'));
        $this->assertInstanceOf(PackageReader::class, $container->get(PackageReader::class));
        $this->assertInstanceOf(PackageWriter::class, $container->get(PackageWriter::class));

        $limits = $container->get(PackageLimits::class);
        $this->assertSame(256 * 1024 * 1024, $limits->maxPackageSize);
        $this->assertSame(2000, $limits->maxFiles);

        $identity = $container->get(InstanceIdentity::class);
        $this->assertSame(16, \strlen($identity->getId()));
        $this->assertSame('cli', $identity->getLabel());
    }

    public function testConfigurationReachesTheServices(): void
    {
        $container = $this->container([
            'instance_id' => 'staging',
            'instance_label' => 'Staging',
            'limits' => ['max_package_size' => '10M', 'max_files' => 12],
            'work_dir' => '/data/transfer',
            'admin' => ['toolbar' => 'buttons', 'merge_csv_export' => false, 'article_csv_export' => true],
        ]);

        $this->assertSame('buttons', $container->getParameter('sulu_content_transfer.admin.toolbar'));
        $this->assertFalse($container->getParameter('sulu_content_transfer.admin.merge_csv_export'));
        $this->assertTrue($container->getParameter('sulu_content_transfer.admin.article_csv_export'));

        $limits = $container->get(PackageLimits::class);
        $this->assertSame(10 * 1024 * 1024, $limits->maxPackageSize);
        $this->assertSame(12, $limits->maxFiles);
        $this->assertSame('/data/transfer', $container->getParameter('sulu_content_transfer.work_dir'));
        $this->assertInstanceOf(WorkDirectory::class, $container->get(WorkDirectory::class));

        $identity = $container->get(InstanceIdentity::class);
        $this->assertSame('staging', $identity->getId());
        $this->assertSame('Staging', $identity->getLabel());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.secret', 'a-secret');
        $container->setParameter('kernel.project_dir', '/project');
        $container->setDefinition('request_stack', new Definition(RequestStack::class));

        $extension = new SuluContentTransferExtension();
        $extension->load([$config], $container);

        foreach ([InstanceIdentity::class, PackageLimits::class, WorkDirectory::class, PackageReader::class, PackageWriter::class] as $id) {
            $container->getAlias($id)->setPublic(true);
        }
        // the controllers are public and pull in the services of Sulu, which this container does not have
        $container->removeDefinition(ExportController::class);
        $container->removeDefinition(ImportController::class);
        $container->compile(true);

        return $container;
    }
}
