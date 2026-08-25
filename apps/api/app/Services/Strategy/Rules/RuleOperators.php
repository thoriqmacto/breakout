<?php

namespace App\Services\Strategy\Rules;

/**
 * The comparison operators a rule condition may use, and which field types
 * each one applies to.
 */
class RuleOperators
{
    public const GT = 'gt';

    public const GTE = 'gte';

    public const LT = 'lt';

    public const LTE = 'lte';

    public const EQ = 'eq';

    public const NEQ = 'neq';

    public const BETWEEN = 'between';

    public const IS_TRUE = 'is_true';

    public const IS_FALSE = 'is_false';

    public const IS_NULL = 'is_null';

    public const NOT_NULL = 'not_null';

    /**
     * Operand count each operator expects in `value`: 0 for the unary
     * predicates, 2 for between, 1 otherwise.
     */
    private const ARITY = [
        self::IS_TRUE => 0,
        self::IS_FALSE => 0,
        self::IS_NULL => 0,
        self::NOT_NULL => 0,
        self::BETWEEN => 2,
    ];

    private const NUMBER_OPERATORS = [
        self::GT, self::GTE, self::LT, self::LTE,
        self::EQ, self::NEQ, self::BETWEEN,
        self::IS_NULL, self::NOT_NULL,
    ];

    private const BOOLEAN_OPERATORS = [
        self::IS_TRUE, self::IS_FALSE,
        self::IS_NULL, self::NOT_NULL,
    ];

    /**
     * @return array<int, string>
     */
    public static function forType(string $type): array
    {
        return match ($type) {
            FieldRegistry::TYPE_NUMBER => self::NUMBER_OPERATORS,
            FieldRegistry::TYPE_BOOLEAN => self::BOOLEAN_OPERATORS,
            default => [],
        };
    }

    public static function arity(string $operator): int
    {
        return self::ARITY[$operator] ?? 1;
    }

    /**
     * Human-readable labels for the UI, keyed by operator.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::GT => 'is greater than',
            self::GTE => 'is at least',
            self::LT => 'is less than',
            self::LTE => 'is at most',
            self::EQ => 'equals',
            self::NEQ => 'does not equal',
            self::BETWEEN => 'is between',
            self::IS_TRUE => 'is true',
            self::IS_FALSE => 'is false',
            self::IS_NULL => 'has no value',
            self::NOT_NULL => 'has a value',
        ];
    }
}
