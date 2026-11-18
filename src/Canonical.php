<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Canonical JSON and the two hashes of the contract (PRD section 5.4).
 *
 * Rule: object keys sorted by byte order, no insignificant white space,
 * Unicode and slashes left unescaped, line terminators left unescaped,
 * integral floats written as integers, and an empty array written as `{}`
 * (the context and field values are objects; lists are never empty). The
 * sidebar script implements the same rule, and a shared vector file in the
 * tests keeps the two in step.
 */
final class Canonical
{
    private const FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_THROW_ON_ERROR;

    /**
     * @throws \JsonException When a string is not valid UTF-8.
     */
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalise($value), self::FLAGS);
    }

    /**
     * SHA-256 hex of the canonical JSON of a context object.
     *
     * @param array<string, mixed> $context
     */
    public static function contextHash(array $context): string
    {
        return hash('sha256', self::encode($context));
    }

    /**
     * SHA-256 hex of the canonical JSON of an item's type and value.
     *
     * @param string|array<int|string, string> $value
     */
    public static function itemHash(string $type, string|array $value): string
    {
        return hash('sha256', self::encode(['type' => $type, 'value' => $value]));
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return new \stdClass();
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'normalise'], $value);
        }
        $keys = array_map('strval', array_keys($value));
        $sorted = array_combine($keys, array_values($value));
        ksort($sorted, SORT_STRING);
        $object = new \stdClass();
        foreach ($sorted as $key => $item) {
            $object->{$key} = self::normalise($item);
        }
        return $object;
    }
}
