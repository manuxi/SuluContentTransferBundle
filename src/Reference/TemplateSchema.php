<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Reference;

/**
 * The fields of an article template, reduced to what the reference scanner needs: the name and the type of every field,
 * the parameters of the field (e.g. the provider of a smart_content field) and, for blocks, the fields of every block type.
 *
 * Sections of the template are already flattened away. The structure is a plain array so tests can write it by hand:
 *
 *     ['image' => ['type' => 'single_media_selection'],
 *      'blocks' => ['type' => 'block', 'types' => ['text' => ['body' => ['type' => 'text_editor']]]]]
 */
final class TemplateSchema
{
    /**
     * @param array<string, array{type: string, params?: array<string, mixed>, types?: array<string, array<string, mixed>>}> $fields
     */
    public function __construct(private readonly array $fields)
    {
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function fromArray(array $fields): self
    {
        /** @var array<string, array{type: string, params?: array<string, mixed>, types?: array<string, array<string, mixed>>}> $fields */
        return new self($fields);
    }

    /**
     * @return array<string, array{type: string, params?: array<string, mixed>, types?: array<string, array<string, mixed>>}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * The schema of one block type, or null when the block has no such type.
     */
    public function blockType(string $field, string $type): ?self
    {
        $types = $this->fields[$field]['types'] ?? [];
        if (!isset($types[$type])) {
            return null;
        }

        return self::fromArray($types[$type]);
    }

    /**
     * @return array<string, array{type: string, params?: array<string, mixed>, types?: array<string, array<string, mixed>>}>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
