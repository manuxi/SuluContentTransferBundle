<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sulu_content_transfer');
        $root = $treeBuilder->getRootNode();

        // PHPStan 1.x cannot resolve the generic NodeBuilder of newer symfony/config versions
        // @phpstan-ignore-next-line
        $root
            ->children()
                ->scalarNode('instance_id')
                    ->defaultNull()
                    ->info('Identifies this system as the source of exported packages (letters, digits, ".", "_" and "-", up to 64 characters). Default: a hash of kernel.secret (16 characters), so copies of the database keep distinct identities as long as the secrets differ.')
                ->end()
                ->scalarNode('instance_label')
                    ->defaultNull()
                    ->info('Readable name of this system in packages and reports. Default: the host of the current request, "cli" on the console.')
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_package_size')
                            ->defaultValue(256 * 1024 * 1024)
                            ->min(1)
                            ->beforeNormalization()
                                ->ifString()
                                ->then(static fn (string $value): int|string => self::parseSize($value))
                            ->end()
                            ->info('Maximum size of a package in bytes, for the file and for the unpacked content. Short forms like "256M" or "1G" are accepted. Default: 256 MB.')
                        ->end()
                        ->integerNode('max_files')
                            ->defaultValue(2000)
                            ->min(1)
                            ->info('Maximum number of files in a package. Default: 2000.')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('work_dir')
                    ->defaultValue('%kernel.project_dir%/var/content-transfer')
                    ->cannotBeEmpty()
                    ->info('Directory for unpacked packages and temporary files. Every package gets its own subdirectory.')
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * Turns "256M" into bytes; anything that is not a size stays as it is for the validation to reject.
     */
    private static function parseSize(string $value): int|string
    {
        if (!preg_match('/^\s*(\d+)\s*([kmg]?)b?\s*$/i', $value, $matches)) {
            return $value;
        }

        $factor = match (strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };

        return (int) $matches[1] * $factor;
    }
}
