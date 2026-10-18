<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\DependencyInjection;

use Manuxi\SuluContentTransferBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        $this->assertNull($config['instance_id']);
        $this->assertNull($config['instance_label']);
        $this->assertSame(256 * 1024 * 1024, $config['limits']['max_package_size']);
        $this->assertSame(2000, $config['limits']['max_files']);
        $this->assertSame('%kernel.project_dir%/var/content-transfer', $config['work_dir']);
    }

    public function testValuesAreTaken(): void
    {
        $config = $this->process([
            'instance_id' => 'local-dev',
            'instance_label' => 'Local',
            'limits' => ['max_package_size' => 1000, 'max_files' => 5],
            'work_dir' => '/tmp/transfer',
        ]);

        $this->assertSame('local-dev', $config['instance_id']);
        $this->assertSame('Local', $config['instance_label']);
        $this->assertSame(1000, $config['limits']['max_package_size']);
        $this->assertSame(5, $config['limits']['max_files']);
        $this->assertSame('/tmp/transfer', $config['work_dir']);
    }

    /**
     * @return iterable<string, array{int|string, int}>
     */
    public static function sizes(): iterable
    {
        yield 'bytes as text' => ['2048', 2048];
        yield 'kilobytes' => ['64K', 64 * 1024];
        yield 'megabytes' => ['256M', 256 * 1024 * 1024];
        yield 'megabytes with unit' => ['10 MB', 10 * 1024 * 1024];
        yield 'gigabytes in lower case' => ['1g', 1024 * 1024 * 1024];
        yield 'bytes as number' => [4096, 4096];
    }

    /**
     * @dataProvider sizes
     */
    public function testSizesAcceptShortForms(int|string $value, int $expected): void
    {
        $this->assertSame($expected, $this->process(['limits' => ['max_package_size' => $value]])['limits']['max_package_size']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'size that is no size' => [['limits' => ['max_package_size' => 'huge']]];
        yield 'size zero' => [['limits' => ['max_package_size' => 0]]];
        yield 'no files allowed' => [['limits' => ['max_files' => 0]]];
        yield 'empty work directory' => [['work_dir' => '']];
        yield 'unknown option' => [['something' => true]];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @dataProvider invalidConfigurations
     */
    public function testInvalidConfigurationIsRefused(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
