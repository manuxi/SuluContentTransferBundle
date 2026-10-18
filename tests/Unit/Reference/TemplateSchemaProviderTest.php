<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Reference;

use Manuxi\SuluContentTransferBundle\Reference\TemplateSchemaProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;

class TemplateSchemaProviderTest extends TestCase
{
    private static function field(string $name, string $type): FieldMetadata
    {
        $field = new FieldMetadata($name);
        $field->setType($type);

        return $field;
    }

    private function provider(?TypedFormMetadata $typed, int &$calls = 0): TemplateSchemaProvider
    {
        $metadataProvider = $this->createMock(MetadataProviderInterface::class);
        $metadataProvider->method('getMetadata')->willReturnCallback(function (string $key, string $locale) use ($typed, &$calls) {
            ++$calls;
            $this->assertSame('article', $key);

            return $typed ?? new FormMetadata();
        });
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => 'form' === $id);
        $container->method('get')->with('form')->willReturn($metadataProvider);

        return new TemplateSchemaProvider(new MetadataProviderRegistry($container));
    }

    private static function typed(): TypedFormMetadata
    {
        $form = new FormMetadata();
        $form->setKey('tutorial');

        // fields inside sections (also nested ones) are flattened
        $section = new SectionMetadata('header');
        $section->addItem(self::field('title', 'text_line'));
        $inner = new SectionMetadata('images');
        $inner->addItem(self::field('gallery', 'media_selection'));
        $section->addItem($inner);
        $form->addItem($section);

        $list = self::field('related', 'smart_content');
        $provider = new OptionMetadata();
        $provider->setName('provider');
        $provider->setValue('articles');
        $collection = new OptionMetadata();
        $collection->setName('properties');
        $collection->setType(OptionMetadata::TYPE_COLLECTION);
        $list->addOption($provider);
        $list->addOption($collection);
        $form->addItem($list);

        $block = self::field('sections', 'block');
        $text = new FormMetadata();
        $text->setKey('text');
        $text->addItem(self::field('body', 'text_editor'));
        $text->addItem(self::field('image', 'single_media_selection'));
        $gallery = new FormMetadata();
        $gallery->setKey('gallery');
        $gallery->addItem(self::field('images', 'media_selection'));
        $block->addType($text);
        $block->addType($gallery);
        $form->addItem($block);

        $typed = new TypedFormMetadata();
        $typed->addForm('tutorial', $form);

        return $typed;
    }

    public function testReadsTheFieldTypesOfATemplateIncludingBlockTypesAndParameters(): void
    {
        $schema = $this->provider(self::typed())->getSchema('tutorial', 'de');

        $this->assertSame([
            'title' => ['type' => 'text_line'],
            'gallery' => ['type' => 'media_selection'],
            'related' => ['type' => 'smart_content', 'params' => ['provider' => 'articles']],
            'sections' => ['type' => 'block', 'types' => [
                'text' => ['body' => ['type' => 'text_editor'], 'image' => ['type' => 'single_media_selection']],
                'gallery' => ['images' => ['type' => 'media_selection']],
            ]],
        ], $schema->toArray());
        $this->assertSame(['images' => ['type' => 'media_selection']], $schema->blockType('sections', 'gallery')?->toArray());
        $this->assertNull($schema->blockType('sections', 'removed'));
        $this->assertNull($schema->blockType('title', 'x'));
    }

    public function testAskSulusMetadataOncePerTemplateAndLanguage(): void
    {
        $calls = 0;
        $provider = $this->provider(self::typed(), $calls);

        $provider->getSchema('tutorial', 'de');
        $provider->getSchema('tutorial', 'de');
        $provider->getSchema('tutorial', 'en');

        $this->assertSame(2, $calls);
    }

    public function testUnknownTemplateIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The template "missing" does not exist on this system.');

        $this->provider(self::typed())->getSchema('missing', 'de');
    }

    public function testMetadataWithoutTemplatesIsReported(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->provider(null)->getSchema('tutorial', 'de');
    }
}
