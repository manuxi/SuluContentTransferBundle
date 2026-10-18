<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;

/**
 * Stable JSON for the package files: UTF-8, no escaping of "/" and unicode, keys of objects sorted, lists keep their
 * order. The same data always produces the same bytes, which keeps packages comparable and diffable.
 *
 * Empty objects survive a round trip: they are decoded as an empty \stdClass (not as an empty array, which would turn
 * into a list), because some values such as the "smart_content" selection must stay "{}".
 */
final class Json
{
    private const ENCODE_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        try {
            return json_encode(self::normalize($value), self::ENCODE_FLAGS)."\n";
        } catch (\JsonException $exception) {
            throw new InvalidPackageException('The data cannot be written as JSON: '.$exception->getMessage(), 'package.invalid', $exception);
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function decode(string $json, string $what = 'file'): array
    {
        try {
            $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidPackageException(\sprintf('The %s is not valid JSON.', $what), 'package.invalid', $exception);
        }

        if (!$decoded instanceof \stdClass) {
            throw new InvalidPackageException(\sprintf('The %s must contain a JSON object.', $what));
        }

        $result = self::fromDecoded($decoded);

        return \is_array($result) ? $result : [];
    }

    private static function fromDecoded(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if ([] === $properties) {
                return new \stdClass();
            }

            return array_map(self::fromDecoded(...), $properties);
        }

        if (\is_array($value)) {
            return array_map(self::fromDecoded(...), $value);
        }

        return $value;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            if ([] === $value) {
                return new \stdClass();
            }
        }

        if (!\is_array($value)) {
            return $value;
        }

        $normalized = array_map(self::normalize(...), $value);

        if (array_is_list($normalized)) {
            return $normalized;
        }

        uksort($normalized, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));

        return $normalized;
    }
}
