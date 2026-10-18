<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SuluContentTransferExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('sulu_content_transfer.instance_id', $config['instance_id']);
        $container->setParameter('sulu_content_transfer.instance_label', $config['instance_label']);
        $container->setParameter('sulu_content_transfer.limits.max_package_size', $config['limits']['max_package_size']);
        $container->setParameter('sulu_content_transfer.limits.max_files', $config['limits']['max_files']);
        $container->setParameter('sulu_content_transfer.work_dir', $config['work_dir']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
    }
}
