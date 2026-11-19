<?php

declare(strict_types=1);

namespace ChatParts\Model;

use BackedEnum;
use InvalidArgumentException;

/**
 * @internal Typed reads from a stored row for the model's fromArray factories. Every bad key
 * (missing, wrong type, unknown enum value) becomes one InvalidArgumentException naming the
 * record and the key, so a corrupt row never surfaces as a PHP warning or TypeError.
 */
final class Fields
{
    /** @param array<array-key, mixed> $a */
    public static function string(array $a, string $key, string $what): string
    {
        $v = self::required($a, $key, $what);
        if (!is_string($v)) {
            throw self::bad($what, $key, 'a string', $v);
        }

        return $v;
    }

    /** Missing and null both read as null. @param array<array-key, mixed> $a */
    public static function nullableString(array $a, string $key, string $what): ?string
    {
        $v = $a[$key] ?? null;
        if ($v !== null && !is_string($v)) {
            throw self::bad($what, $key, 'a string or null', $v);
        }

        return $v;
    }

    /** @param array<array-key, mixed> $a */
    public static function int(array $a, string $key, string $what): int
    {
        $v = self::required($a, $key, $what);
        if (!is_int($v)) {
            throw self::bad($what, $key, 'an integer', $v);
        }

        return $v;
    }

    /** Missing and null both read as null. @param array<array-key, mixed> $a */
    public static function nullableInt(array $a, string $key, string $what): ?int
    {
        $v = $a[$key] ?? null;
        if ($v !== null && !is_int($v)) {
            throw self::bad($what, $key, 'an integer or null', $v);
        }

        return $v;
    }

    /**
     * An array value; a missing key reads as [] when $optional.
     *
     * @param array<array-key, mixed> $a
     * @return array<array-key, mixed>
     */
    public static function array(array $a, string $key, string $what, bool $optional = false): array
    {
        $v = $optional ? ($a[$key] ?? []) : self::required($a, $key, $what);
        if (!is_array($v)) {
            throw self::bad($what, $key, 'an array', $v);
        }

        return $v;
    }

    /**
     * @template T of BackedEnum
     * @param array<array-key, mixed> $a
     * @param class-string<T>         $enum
     * @return T
     */
    public static function enum(array $a, string $key, string $what, string $enum): BackedEnum
    {
        $v = self::string($a, $key, $what);
        $case = $enum::tryFrom($v);
        if ($case === null) {
            throw new InvalidArgumentException(sprintf("Malformed %s: unknown '%s' value %s.", $what, $key, var_export($v, true)));
        }

        return $case;
    }

    /** @param array<array-key, mixed> $a */
    private static function required(array $a, string $key, string $what): mixed
    {
        if (!array_key_exists($key, $a)) {
            throw new InvalidArgumentException(sprintf("Malformed %s: missing '%s'.", $what, $key));
        }

        return $a[$key];
    }

    private static function bad(string $what, string $key, string $expected, mixed $v): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf("Malformed %s: '%s' must be %s, got %s.", $what, $key, $expected, get_debug_type($v)));
    }
}
