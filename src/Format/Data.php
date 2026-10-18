<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Format;

use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;

/**
 * Typed access to decoded JSON for the value objects. Every violation raises an InvalidPackageException that names
 * the field, so a broken package is reported precisely instead of failing later with a TypeError.
 *
 * @internal
 */
final class Data
{
    /**
     * @param array<int|string, mixed> $data
     */
    public static function string(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw self::error($path, $key, 'a string');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function nullableString(array $data, string $key, string $path): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw self::error($path, $key, 'a string or null');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function int(array $data, string $key, string $path): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw self::error($path, $key, 'an integer');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function nullableInt(array $data, string $key, string $path): ?int
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_int($value)) {
            throw self::error($path, $key, 'an integer or null');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function bool(array $data, string $key, string $path): bool
    {
        $value = $data[$key] ?? null;
        if (!\is_bool($value)) {
            throw self::error($path, $key, 'true or false');
        }

        return $value;
    }

    /**
     * An id that is a number in one system and a UUID in another.
     *
     * @param array<int|string, mixed> $data
     */
    public static function id(array $data, string $key, string $path): int|string
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) && !\is_string($value)) {
            throw self::error($path, $key, 'an integer or a string');
        }

        return $value;
    }

    /**
     * A JSON object: a (possibly empty) map. An empty object arrives as an empty \stdClass, see Json.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public static function map(array $data, string $key, string $path): array
    {
        $value = $data[$key] ?? null;
        if ($value instanceof \stdClass) {
            return [];
        }
        if (!\is_array($value)) {
            throw self::error($path, $key, 'an object');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key, string $path): array
    {
        $result = [];
        foreach (self::map($data, $key, $path) as $name => $value) {
            if (!\is_string($value)) {
                throw self::error($path, $key.'.'.$name, 'a string');
            }
            $result[(string) $name] = $value;
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<mixed>
     */
    public static function list(array $data, string $key, string $path): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw self::error($path, $key, 'a list');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<string>
     */
    public static function stringList(array $data, string $key, string $path): array
    {
        $result = [];
        foreach (self::list($data, $key, $path) as $index => $value) {
            if (!\is_string($value)) {
                throw self::error($path, $key.'.'.$index, 'a string');
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function dateTime(array $data, string $key, string $path): \DateTimeImmutable
    {
        $value = self::nullableDateTime($data, $key, $path);
        if (null === $value) {
            throw self::error($path, $key, 'a date and time (ISO 8601)');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function nullableDateTime(array $data, string $key, string $path): ?\DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw self::error($path, $key, 'a date and time (ISO 8601)');
        }

        $date = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);
        if (false === $date) {
            throw self::error($path, $key, 'a date and time (ISO 8601)');
        }

        return $date;
    }

    /**
     * Empty maps must be written as "{}", not as "[]".
     *
     * @param array<int|string, mixed> $map
     *
     * @return array<int|string, mixed>|\stdClass
     */
    public static function mapOut(array $map): array|\stdClass
    {
        return [] === $map ? new \stdClass() : $map;
    }

    public static function dateOut(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DATE_ATOM);
    }

    public static function error(string $path, string $key, string $expected): InvalidPackageException
    {
        return new InvalidPackageException(\sprintf('Invalid package: "%s.%s" must be %s.', $path, $key, $expected));
    }

    public static function assertSha256(string $value, string $path): void
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $value)) {
            throw new InvalidPackageException(\sprintf('Invalid package: "%s" must be a lowercase SHA-256 hash.', $path));
        }
    }

    public static function assertSupportedStage(string $stage, string $path): void
    {
        if (!\in_array($stage, ['live', 'draft'], true)) {
            throw new InvalidPackageException(\sprintf('Invalid package: "%s" must be "live" or "draft".', $path));
        }
    }
}
