<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SuluContentTransferExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Key of the system collection that receives images the target system does not have yet and that cannot be
     * placed in a folder of the same key as on the source system.
     */
    public const SYSTEM_COLLECTION_KEY = 'sulu_content_transfer';

    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'SuluContentTransferBundle' => [
                            'type' => 'xml',
                            'dir' => __DIR__.'/../Resources/config/doctrine',
                            'prefix' => 'Manuxi\SuluContentTransferBundle\Entity',
                            'alias' => 'SuluContentTransferBundle',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'paths' => [
                        __DIR__.'/../Resources/translations',
                    ],
                ],
            ]);
        }

        if ($container->hasExtension('sulu_media')) {
            $container->prependExtensionConfig('sulu_media', [
                'system_collections' => [
                    self::SYSTEM_COLLECTION_KEY => [
                        'meta_title' => ['en' => 'Import', 'de' => 'Import'],
                    ],
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('sulu_content_transfer.instance_id', $config['instance_id']);
        $container->setParameter('sulu_content_transfer.instance_label', $config['instance_label']);
        $container->setParameter('sulu_content_transfer.admin.toolbar', $config['admin']['toolbar']);
        $container->setParameter('sulu_content_transfer.admin.merge_csv_export', $config['admin']['merge_csv_export']);
        $container->setParameter('sulu_content_transfer.limits.max_package_size', $config['limits']['max_package_size']);
        $container->setParameter('sulu_content_transfer.limits.max_files', $config['limits']['max_files']);
        $container->setParameter('sulu_content_transfer.work_dir', $config['work_dir']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
    }
}
