<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Reference;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;

/**
 * Reads the field types of an article template from the form metadata of Sulu, the same source the admin form
 * and the data mapper of Sulu use.
 */
class TemplateSchemaProvider
{
    private const FORM_KEY = 'article';

    /**
     * @var array<string, TemplateSchema>
     */
    private array $cache = [];

    public function __construct(private readonly MetadataProviderRegistry $metadataProviderRegistry)
    {
    }

    /**
     * @throws \RuntimeException when the system does not know the template
     */
    public function getSchema(string $templateKey, string $locale): TemplateSchema
    {
        $cacheKey = $templateKey.'|'.$locale;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $typed = $this->metadataProviderRegistry->getMetadataProvider('form')->getMetadata(self::FORM_KEY, $locale, []);
        if (!$typed instanceof TypedFormMetadata) {
            throw new \RuntimeException(\sprintf('The form metadata of "%s" has no templates.', self::FORM_KEY));
        }

        $form = $typed->getForms()[$templateKey] ?? null;
        if (!$form instanceof FormMetadata) {
            throw new \RuntimeException(\sprintf('The template "%s" does not exist on this system.', $templateKey));
        }

        return $this->cache[$cacheKey] = TemplateSchema::fromArray($this->fieldsOf($form->getItems()));
    }

    /**
     * @param array<ItemMetadata> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldsOf(array $items): array
    {
        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                $fields = [...$fields, ...$this->fieldsOf($item->getItems())];

                continue;
            }

            $spec = ['type' => $item->getType()];
            if ($item instanceof FieldMetadata) {
                $params = [];
                foreach ($item->getOptions() as $option) {
                    $value = $option->getValue();
                    if (\is_scalar($value)) {
                        $params[(string) $option->getName()] = $value;
                    }
                }
                if ([] !== $params) {
                    $spec['params'] = $params;
                }

                $types = [];
                foreach ($item->getTypes() as $typeName => $typeForm) {
                    $types[(string) $typeName] = $this->fieldsOf($typeForm->getItems());
                }
                if ([] !== $types) {
                    $spec['types'] = $types;
                }
            }

            $fields[$item->getName()] = $spec;
        }

        return $fields;
    }
}
