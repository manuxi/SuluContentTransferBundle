<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import;

use Manuxi\SuluContentTransferBundle\Import\Write\SuluAdminLinks;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;

class SuluAdminLinksTest extends TestCase
{
    private const UUID = '0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8';

    public function testLinksToTheEditViewOfTheGroupOfTheTemplate(): void
    {
        $links = $this->links();

        $this->assertSame('/admin/#/de/blog/'.self::UUID, $links->article(self::UUID, 'de', 'blog-post'));
        $this->assertSame('/admin/#/en/faq/'.self::UUID, $links->article(self::UUID, 'en', 'faq-question'));
    }

    public function testAnUnknownTemplateGetsTheFirstGroup(): void
    {
        $this->assertSame('/admin/#/de/blog/'.self::UUID, $this->links()->article(self::UUID, 'de', 'other'));
        $this->assertSame('/admin/#/de/blog/'.self::UUID, $this->links()->article(self::UUID, 'de', null));
    }

    public function testWithoutGroupsThereIsNoLink(): void
    {
        $provider = $this->createMock(GroupProviderInterface::class);
        $provider->method('getGroups')->willReturn([]);

        $this->assertNull((new SuluAdminLinks($provider))->article(self::UUID, 'de', 'blog-post'));
    }

    private function links(): SuluAdminLinks
    {
        $provider = $this->createMock(GroupProviderInterface::class);
        $provider->method('getGroups')->willReturn([
            'blog' => new FormGroup('blog', 'Blog', ['blog-post']),
            'faq' => new FormGroup('faq', 'FAQ', ['faq-question']),
        ]);

        return new SuluAdminLinks($provider);
    }
}
