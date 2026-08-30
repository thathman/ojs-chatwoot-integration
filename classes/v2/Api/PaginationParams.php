<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

/**
 * Small, reusable bounded-pagination parser for Support API list endpoints.
 * Fails closed (returns null) on anything malformed rather than silently
 * clamping — a caller asking for something invalid gets a real validation
 * error, not a surprising best-effort guess.
 */
final class PaginationParams
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 50;

    private function __construct(
        public readonly int $limit,
        public readonly int $offset
    ) {
    }

    /** Returns null on any malformed/out-of-bounds input. */
    public static function parse(mixed $rawLimit, mixed $rawOffset): ?self
    {
        $limit = self::parsePositiveInt($rawLimit, self::DEFAULT_LIMIT);
        if ($limit === null || $limit < 1 || $limit > self::MAX_LIMIT) {
            return null;
        }

        $offset = self::parseNonNegativeInt($rawOffset, 0);
        if ($offset === null) {
            return null;
        }

        return new self($limit, $offset);
    }

    private static function parsePositiveInt(mixed $value, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value))) {
            return (int) trim($value);
        }
        return null;
    }

    private static function parseNonNegativeInt(mixed $value, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', trim($value))) {
            return (int) trim($value);
        }
        return null;
    }
}
