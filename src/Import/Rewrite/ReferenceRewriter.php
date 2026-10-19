<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Rewrite;

use Manuxi\SuluContentTransferBundle\Format\ArticleReference;

/**
 * Rewrites every reference in the content of one language (the admin normal form of the package) so it points to the
 * right thing on this system. It works from the reference list of the package: each entry names the place ("path",
 * dots, a number is a list index) and the target (kind and id on the source system).
 *
 *  - A place that holds the id itself (media ids in lists and objects, UUIDs, category and tag ids, a contact id, the
 *    "href" of a link field, "contacts and accounts" values such as "c12") gets the new id.
 *  - A place that holds a text (the text editor) has all its "sulu-link" tags rewritten the same way. A link whose
 *    target is gone is taken out of the text; the words of the link stay.
 *  - A reference that cannot be resolved is removed: the element of a list is deleted, a single value becomes null, the
 *    whole value of a link or of an image selection becomes null, and a smart content whose data source is gone becomes
 *    an empty selection ("{}", never null).
 *
 * The tag filters of a smart content hold names, not ids; they are left as they are.
 */
final class ReferenceRewriter
{
    /** provider of a "sulu-link" and of a link field => kind of the target */
    private const LINK_PROVIDERS = [
        'page' => 'pages',
        'article' => 'articles',
        'media' => 'media',
        'snippet' => 'snippets',
    ];

    /** keys that hold the id of a target next to other properties of the same value */
    private const ID_KEYS = ['id', 'uuid', 'href'];

    /**
     * @param array<int|string, mixed> $data       content of one language (admin normal form)
     * @param list<ArticleReference>   $references references of the package; entries of other languages are ignored
     */
    public function rewrite(string $locale, array $data, array $references, RewriteMap $map): RewriteResult
    {
        $data = self::plain($data);

        /** @var array<string, list<string>> $textPaths */
        $textPaths = [];
        /** @var array<string, array{tokens: list<string>, set: bool, value: mixed}> $operations */
        $operations = [];
        /** @var array<string, RemovedReference> $removed */
        $removed = [];

        foreach ($references as $reference) {
            if ($reference->locale !== $locale) {
                continue;
            }
            $kind = $reference->resourceKey;
            if ('tags' === $kind && \is_string($reference->id)) {
                continue;
            }

            $tokens = explode('.', $reference->path);
            $found = false;
            $value = self::get($data, $tokens, $found);
            if (!$found || null === $value) {
                continue;
            }

            if (\is_string($value) && null === self::directPrefix($value, $kind, $reference->id)) {
                $textPaths[$reference->path] = $tokens;

                continue;
            }
            if (!\is_string($value) && !\is_int($value)) {
                continue;
            }

            $rewrite = $map->resolve($kind, $reference->id);
            if (Rewrite::REPLACE === $rewrite->action && null !== $rewrite->value) {
                self::set($data, $tokens, self::sameType($value, $rewrite->value, self::directPrefix((string) $value, $kind, $reference->id)));
            } elseif (Rewrite::REMOVE === $rewrite->action) {
                $operation = self::removal($tokens, $data);
                $operations[implode("\0", $operation['tokens']).($operation['set'] ? "\0set" : "\0unset")] = $operation;
                $removed[$reference->path."\0".$kind."\0".$reference->id] = new RemovedReference($locale, $reference->path, $kind, $reference->id);
            }
        }

        foreach ($textPaths as $path => $tokens) {
            $found = false;
            $text = self::get($data, $tokens, $found);
            if (!$found || !\is_string($text)) {
                continue;
            }
            self::set($data, $tokens, $this->rewriteText($locale, $path, $text, $map, $removed));
        }

        uasort($operations, static fn (array $a, array $b): int => self::compare($b['tokens'], $a['tokens']));
        foreach ($operations as $operation) {
            if ($operation['set']) {
                self::set($data, $operation['tokens'], $operation['value']);
            } else {
                self::unsetAt($data, $operation['tokens']);
            }
        }

        return new RewriteResult($data, array_values($removed));
    }

    /**
     * @param array<string, RemovedReference> $removed
     */
    private function rewriteText(string $locale, string $path, string $text, RewriteMap $map, array &$removed): string
    {
        $result = preg_replace_callback(
            '#<sulu-link\b([^>]*)>(.*?)</sulu-link>#s',
            function (array $match) use ($locale, $path, $map, &$removed): string {
                $attributes = $match[1];
                if (1 !== preg_match('/\bprovider="([^"]*)"/', $attributes, $provider) || 1 !== preg_match('/\bhref="([^"]*)"/', $attributes, $href)) {
                    return $match[0];
                }
                $kind = self::LINK_PROVIDERS[$provider[1]] ?? null;
                if (null === $kind) {
                    return $match[0];
                }
                $id = $href[1];
                $rewrite = $map->resolve($kind, ctype_digit($id) ? (int) $id : $id);

                if (Rewrite::REMOVE === $rewrite->action) {
                    $removed[$path."\0".$kind."\0".$id] = new RemovedReference($locale, $path, $kind, ctype_digit($id) ? (int) $id : $id);

                    return $match[2];
                }
                if (Rewrite::REPLACE === $rewrite->action && null !== $rewrite->value) {
                    $new = preg_replace_callback(
                        '/\bhref="[^"]*"/',
                        static fn (): string => 'href="'.htmlspecialchars((string) $rewrite->value, \ENT_QUOTES).'"',
                        $attributes,
                        1,
                    );

                    return '<sulu-link'.($new ?? $attributes).'>'.$match[2].'</sulu-link>';
                }

                return $match[0];
            },
            $text,
        );

        return $result ?? $text;
    }

    /**
     * Whether the value is the id itself and not a text around it. Returns the prefix of a "contacts and accounts"
     * value ("c", "a"), an empty string for a plain id, null if the value is a text.
     */
    private static function directPrefix(string $value, string $kind, int|string $id): ?string
    {
        if ($value === (string) $id) {
            return '';
        }
        if (\in_array($kind, ['contacts', 'accounts'], true) && 1 === preg_match('/^([ca])(\d+)\z/', $value, $match) && $match[2] === (string) $id) {
            return $match[1];
        }

        return null;
    }

    private static function sameType(int|string $original, int|string $new, ?string $prefix): int|string
    {
        if ('' !== $prefix && null !== $prefix) {
            return $prefix.$new;
        }

        return \is_int($original) ? (int) $new : (string) $new;
    }

    /**
     * What removes a reference: the list element, the whole value of a link or image selection, or the single value.
     *
     * @param list<string>             $tokens
     * @param array<int|string, mixed> $data
     *
     * @return array{tokens: list<string>, set: bool, value: mixed}
     */
    private static function removal(array $tokens, array $data): array
    {
        $last = $tokens[\count($tokens) - 1];
        if (self::isIndex($last)) {
            return ['tokens' => $tokens, 'set' => false, 'value' => null];
        }

        $parent = \array_slice($tokens, 0, -1);
        if ('dataSource' === $last && [] !== $parent) {
            // a smart content without its data source: an empty selection, not null
            return ['tokens' => $parent, 'set' => true, 'value' => []];
        }
        if (\in_array($last, self::ID_KEYS, true) && [] !== $parent) {
            if (self::isIndex($parent[\count($parent) - 1])) {
                return ['tokens' => $parent, 'set' => false, 'value' => null];
            }

            return ['tokens' => $parent, 'set' => true, 'value' => null];
        }

        return ['tokens' => $tokens, 'set' => true, 'value' => null];
    }

    private static function isIndex(string $token): bool
    {
        return ctype_digit($token);
    }

    /**
     * Deepest and last first, so removing an element does not move the ones that are still to be removed.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function compare(array $a, array $b): int
    {
        $length = min(\count($a), \count($b));
        for ($index = 0; $index < $length; ++$index) {
            if ($a[$index] === $b[$index]) {
                continue;
            }
            if (self::isIndex($a[$index]) && self::isIndex($b[$index])) {
                return (int) $a[$index] <=> (int) $b[$index];
            }

            return strcmp($a[$index], $b[$index]);
        }

        return \count($a) <=> \count($b);
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $tokens
     */
    private static function get(array $data, array $tokens, bool &$found): mixed
    {
        $current = $data;
        foreach ($tokens as $token) {
            if (!\is_array($current) || !\array_key_exists($token, $current)) {
                $found = false;

                return null;
            }
            $current = $current[$token];
        }
        $found = true;

        return $current;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $tokens
     */
    private static function set(array &$data, array $tokens, mixed $value): void
    {
        $reference = &$data;
        foreach ($tokens as $token) {
            if (!\is_array($reference) || !\array_key_exists($token, $reference)) {
                return;
            }
            $reference = &$reference[$token];
        }
        $reference = $value;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $tokens
     */
    private static function unsetAt(array &$data, array $tokens): void
    {
        $last = array_pop($tokens);
        if (null === $last) {
            return;
        }
        $reference = &$data;
        foreach ($tokens as $token) {
            if (!\is_array($reference) || !\array_key_exists($token, $reference)) {
                return;
            }
            $reference = &$reference[$token];
        }
        if (!\is_array($reference) || !\array_key_exists($last, $reference)) {
            return;
        }
        $wasList = array_is_list($reference);
        unset($reference[$last]);
        if ($wasList) {
            $reference = array_values($reference);
        }
    }

    /**
     * Objects without properties ("{}") become empty arrays: that is the form the messages of Sulu take.
     *
     * @return array<int|string, mixed>
     */
    public static function plain(mixed $value): array
    {
        $converted = self::plainValue($value);

        return \is_array($converted) ? $converted : [];
    }

    private static function plainValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (\is_array($value)) {
            return array_map(self::plainValue(...), $value);
        }

        return $value;
    }
}
