<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Reference;

use Manuxi\SuluContentTransferBundle\Format\ArticleReference;

/**
 * Finds every place in the admin form of an article that points to another resource.
 *
 * Sulu collects references while it resolves the content for the website. That list misses several kinds (links in
 * the text editor, link fields, the filters of smart_content fields, teaser selections, the parent page of the
 * address, media_picker fields), and its paths do not match the admin form (e.g. "settings.author" for the field
 * "author"). This scanner walks the admin form with the field types of the template and reports each place with the
 * path of the value inside the form, which is what the importer needs to rewrite it.
 *
 * Paths are dot separated keys of the admin form, e.g. "gallery.ids.0" or "sections.1.sectionImage.id". A text that
 * contains links (text editor) is reported with the path of the text, once per link.
 */
final class ReferenceScanner
{
    public const MEDIA = 'media';
    public const PAGES = 'pages';
    public const ARTICLES = 'articles';
    public const SNIPPETS = 'snippets';
    public const CATEGORIES = 'categories';
    public const TAGS = 'tags';
    public const CONTACTS = 'contacts';
    public const ACCOUNTS = 'accounts';
    public const COLLECTIONS = 'collections';
    public const TARGET_GROUPS = 'target_groups';

    /**
     * Field types holding a list of ids (the value is a list or, for media, an object with the list under "ids").
     */
    private const LIST_TYPES = [
        'page_selection' => self::PAGES,
        'article_selection' => self::ARTICLES,
        'snippet_selection' => self::SNIPPETS,
        'category_selection' => self::CATEGORIES,
        'tag_selection' => self::TAGS,
        'contact_selection' => self::CONTACTS,
        'account_selection' => self::ACCOUNTS,
        'collection_selection' => self::COLLECTIONS,
    ];

    /**
     * Field types holding one id.
     */
    private const SINGLE_TYPES = [
        'single_page_selection' => self::PAGES,
        'single_article_selection' => self::ARTICLES,
        'single_snippet_selection' => self::SNIPPETS,
        'single_category_selection' => self::CATEGORIES,
        'single_contact_selection' => self::CONTACTS,
        'single_account_selection' => self::ACCOUNTS,
        'single_collection_selection' => self::COLLECTIONS,
    ];

    /**
     * Link providers of the link field and of <sulu-link> in the text editor, and the resource they point to.
     */
    private const LINK_PROVIDERS = [
        'page' => self::PAGES,
        'article' => self::ARTICLES,
        'media' => self::MEDIA,
        'snippet' => self::SNIPPETS,
    ];

    /**
     * Types of the items of a teaser_selection field.
     */
    private const TEASER_TYPES = [
        'pages' => self::PAGES,
        'page' => self::PAGES,
        'articles' => self::ARTICLES,
        'article' => self::ARTICLES,
    ];

    /**
     * Value of the smart_content "provider" parameter and the resource its "dataSource" points to.
     */
    private const DATA_SOURCES = [
        'pages' => self::PAGES,
        'media' => self::COLLECTIONS,
    ];

    /**
     * @param array<string, mixed> $data admin form of one article in one language
     *
     * @return list<ArticleReference>
     */
    public function scan(string $locale, array $data, TemplateSchema $schema): array
    {
        $found = [];

        $this->scanFields($locale, $data, $schema, '', $found);
        $this->scanSettings($locale, $data, $found);

        $unique = [];
        foreach ($found as $reference) {
            $unique[$reference->path . '|' . $reference->resourceKey . '|' . $reference->id] = $reference;
        }
        $references = array_values($unique);
        usort($references, ArticleReference::compare(...));

        return $references;
    }

    /**
     * Places in the admin form that are not fields of the template: the author, the excerpt, the parent page of the address.
     *
     * @param array<string, mixed>   $data
     * @param list<ArticleReference> $found
     */
    private function scanSettings(string $locale, array $data, array &$found): void
    {
        // the author of an article is a contact (Sulu reports it as resource "users", but the value is a contact id)
        if (isset($data['author']) && self::isId($data['author'])) {
            $found[] = new ArticleReference($locale, 'author', self::CONTACTS, $data['author']);
        }

        foreach (['excerptCategories' => self::CATEGORIES, 'excerptTags' => self::TAGS, 'excerptAudienceTargetGroups' => self::TARGET_GROUPS] as $field => $resourceKey) {
            foreach (self::listOf($data[$field] ?? null) as $index => $id) {
                if (self::isId($id)) {
                    $found[] = new ArticleReference($locale, $field . '.' . $index, $resourceKey, $id);
                }
            }
        }

        $excerpt = $data['excerpt'] ?? null;
        if (\is_array($excerpt)) {
            foreach (['image', 'icon'] as $field) {
                $value = $excerpt[$field] ?? null;
                if (\is_array($value) && self::isId($value['id'] ?? null)) {
                    $found[] = new ArticleReference($locale, 'excerpt.' . $field . '.id', self::MEDIA, $value['id']);
                }
            }
            if (\is_string($excerpt['description'] ?? null)) {
                $this->scanText($locale, 'excerpt.description', $excerpt['description'], $found);
            }
        }

        // the parent page of the address of the article
        $url = $data['url'] ?? null;
        if (\is_array($url) && \is_array($url['page'] ?? null) && self::isId($url['page']['uuid'] ?? null)) {
            $found[] = new ArticleReference($locale, 'url.page.uuid', self::PAGES, $url['page']['uuid']);
        }
    }

    /**
     * @param array<string, mixed>   $data
     * @param list<ArticleReference> $found
     */
    private function scanFields(string $locale, array $data, TemplateSchema $schema, string $prefix, array &$found): void
    {
        foreach ($schema->fields() as $name => $spec) {
            if (!\array_key_exists($name, $data)) {
                continue;
            }

            $this->scanValue($locale, $prefix . $name, $data[$name], $spec, $schema, $name, $found);
        }
    }

    /**
     * @param array<string, mixed>   $spec
     * @param list<ArticleReference> $found
     */
    private function scanValue(string $locale, string $path, mixed $value, array $spec, TemplateSchema $schema, string $name, array &$found): void
    {
        $type = \is_string($spec['type'] ?? null) ? $spec['type'] : '';

        if ('block' === $type) {
            $this->scanBlock($locale, $path, $value, $schema, $name, $found);

            return;
        }

        if (isset(self::LIST_TYPES[$type])) {
            foreach (self::listOf($value) as $index => $id) {
                if (self::isId($id)) {
                    $found[] = new ArticleReference($locale, $path . '.' . $index, self::LIST_TYPES[$type], $id);
                }
            }

            return;
        }

        if (isset(self::SINGLE_TYPES[$type])) {
            if (self::isId($value)) {
                $found[] = new ArticleReference($locale, $path, self::SINGLE_TYPES[$type], $value);
            }

            return;
        }

        switch ($type) {
            case 'media_selection':
                $ids = \is_array($value) && \array_key_exists('ids', $value) ? $value['ids'] : $value;
                $idsPath = \is_array($value) && \array_key_exists('ids', $value) ? $path . '.ids' : $path;
                foreach (self::listOf($ids) as $index => $id) {
                    if (self::isId($id)) {
                        $found[] = new ArticleReference($locale, $idsPath . '.' . $index, self::MEDIA, $id);
                    }
                }

                return;

            case 'single_media_selection':
            case 'media_picker': // field type of a third-party bundle: {"id": 12}, same shape as single_media_selection
                if (\is_array($value) && self::isId($value['id'] ?? null)) {
                    $found[] = new ArticleReference($locale, $path . '.id', self::MEDIA, $value['id']);
                }

                return;

            case 'contact_account_selection': // ["c12", "a3"]
                foreach (self::listOf($value) as $index => $id) {
                    if (\is_string($id) && 1 === preg_match('/^([ca])([0-9]+)$/', $id, $match)) {
                        $found[] = new ArticleReference($locale, $path . '.' . $index, 'c' === $match[1] ? self::CONTACTS : self::ACCOUNTS, (int) $match[2]);
                    }
                }

                return;

            case 'text_editor':
                if (\is_string($value)) {
                    $this->scanText($locale, $path, $value, $found);
                }

                return;

            case 'link':
                $this->scanLink($locale, $path, $value, $found);

                return;

            case 'smart_content':
                $params = \is_array($spec['params'] ?? null) ? $spec['params'] : [];
                $this->scanSmartContent($locale, $path, $value, \is_string($params['provider'] ?? null) ? $params['provider'] : null, $found);

                return;

            case 'teaser_selection':
                $items = \is_array($value) ? ($value['items'] ?? null) : null;
                foreach (self::listOf($items) as $index => $item) {
                    $teaserType = \is_array($item) ? ($item['type'] ?? null) : null;
                    if (\is_array($item) && \is_string($teaserType) && isset(self::TEASER_TYPES[$teaserType]) && self::isId($item['id'] ?? null)) {
                        $found[] = new ArticleReference($locale, $path . '.items.' . $index . '.id', self::TEASER_TYPES[$teaserType], $item['id']);
                    }
                }

                return;

            case 'page_tree_route': // the parent page of an address
                if (\is_array($value) && \is_array($value['page'] ?? null) && self::isId($value['page']['uuid'] ?? null)) {
                    $found[] = new ArticleReference($locale, $path . '.page.uuid', self::PAGES, $value['page']['uuid']);
                }

                return;
        }
    }

    /**
     * @param list<ArticleReference> $found
     */
    private function scanBlock(string $locale, string $path, mixed $blocks, TemplateSchema $schema, string $name, array &$found): void
    {
        foreach (self::listOf($blocks) as $index => $block) {
            if (!\is_array($block) || !\is_string($block['type'] ?? null)) {
                continue;
            }

            $blockSchema = $schema->blockType($name, $block['type']);
            if (null === $blockSchema) {
                continue;
            }

            /** @var array<string, mixed> $block */
            $this->scanFields($locale, $block, $blockSchema, $path . '.' . $index . '.', $found);
        }
    }

    /**
     * @param list<ArticleReference> $found
     */
    private function scanText(string $locale, string $path, string $text, array &$found): void
    {
        if (!str_contains($text, '<sulu-link')) {
            return;
        }

        if (1 > preg_match_all('/<sulu-link\b([^>]*)>/i', $text, $tags)) {
            return;
        }

        foreach ($tags[1] as $attributes) {
            $provider = self::attribute($attributes, 'provider');
            $href = self::attribute($attributes, 'href');
            if (null === $provider || null === $href || !isset(self::LINK_PROVIDERS[$provider])) {
                continue;
            }

            $found[] = new ArticleReference($locale, $path, self::LINK_PROVIDERS[$provider], self::LINK_PROVIDERS[$provider] === self::MEDIA && ctype_digit($href) ? (int) $href : $href);
        }
    }

    /**
     * @param list<ArticleReference> $found
     */
    private function scanLink(string $locale, string $path, mixed $value, array &$found): void
    {
        if (!\is_array($value) || !\is_string($value['provider'] ?? null) || !isset(self::LINK_PROVIDERS[$value['provider']])) {
            return;
        }

        $href = $value['href'] ?? null;
        if (!self::isId($href)) {
            return;
        }

        $resourceKey = self::LINK_PROVIDERS[$value['provider']];
        $found[] = new ArticleReference($locale, $path . '.href', $resourceKey, self::MEDIA === $resourceKey && \is_string($href) && ctype_digit($href) ? (int) $href : $href);
    }

    /**
     * The value of a smart_content field holds the filters. Sulu does not report them as references.
     *
     * @param list<ArticleReference> $found
     */
    private function scanSmartContent(string $locale, string $path, mixed $value, ?string $provider, array &$found): void
    {
        if (!\is_array($value)) {
            return;
        }

        foreach (self::listOf($value['categories'] ?? null) as $index => $id) {
            if (self::isId($id)) {
                $found[] = new ArticleReference($locale, $path . '.categories.' . $index, self::CATEGORIES, $id);
            }
        }

        // the tag filter stores the names of the tags, not their ids
        foreach (self::listOf($value['tags'] ?? null) as $index => $name) {
            if (\is_string($name) && '' !== $name) {
                $found[] = new ArticleReference($locale, $path . '.tags.' . $index, self::TAGS, $name);
            }
        }

        $dataSource = $value['dataSource'] ?? null;
        if (null !== $provider && isset(self::DATA_SOURCES[$provider]) && self::isId($dataSource)) {
            $found[] = new ArticleReference($locale, $path . '.dataSource', self::DATA_SOURCES[$provider], $dataSource);
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function listOf(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @phpstan-assert-if-true int|string $value
     */
    private static function isId(mixed $value): bool
    {
        return (\is_int($value) && $value > 0) || (\is_string($value) && '' !== $value);
    }

    private static function attribute(string $attributes, string $name): ?string
    {
        if (1 !== preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attributes, $match)) {
            return null;
        }

        $raw = $match[1];
        if ('' === $raw && isset($match[2])) {
            $raw = $match[2];
        }
        $value = html_entity_decode($raw, \ENT_QUOTES | \ENT_HTML5);

        return '' === $value ? null : $value;
    }
}
