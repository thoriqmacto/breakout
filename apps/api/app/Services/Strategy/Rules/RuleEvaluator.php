<?php

namespace App\Services\Strategy\Rules;

/**
 * Evaluates a validated rule tree against one flattened row of field values.
 *
 * Pure: no IO, no model access. The runner is responsible for assembling the
 * row. Every condition contributes to an explanation trace so a match can be
 * justified after the fact -- the same reason the existing watchlist scoring
 * persists its reasons.
 */
class RuleEvaluator
{
    /**
     * @param  array<string, mixed>  $tree  A tree that has passed RuleValidator.
     * @param  array<string, mixed>  $row  Field values keyed as "<source>.<column>".
     * @return array{matched: bool, explanation: array<int, array<string, mixed>>}
     */
    public function evaluate(array $tree, array $row): array
    {
        $explanation = [];
        $matched = $this->node($tree, $row, $explanation);

        return ['matched' => $matched, 'explanation' => $explanation];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $explanation
     */
    private function node(array $node, array $row, array &$explanation): bool
    {
        if (array_key_exists('op', $node)) {
            return $this->group($node, $row, $explanation);
        }

        return $this->condition($node, $row, $explanation);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $explanation
     */
    private function group(array $node, array $row, array &$explanation): bool
    {
        $isAnd = strtolower((string) $node['op']) === 'and';
        $children = is_array($node['rules'] ?? null) ? $node['rules'] : [];

        // Deliberately not short-circuiting: every condition is evaluated so
        // the explanation shows why each one passed or failed, which is the
        // point of the trace. Rule trees are capped at 50 conditions, so the
        // cost of evaluating all of them is bounded and small.
        $result = $isAnd;

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $childResult = $this->node($child, $row, $explanation);

            $result = $isAnd ? ($result && $childResult) : ($result || $childResult);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $explanation
     */
    private function condition(array $node, array $row, array &$explanation): bool
    {
        $field = (string) ($node['field'] ?? '');
        $operator = strtolower((string) ($node['operator'] ?? ''));
        $expected = $node['value'] ?? null;
        $actual = $row[$field] ?? null;

        $passed = $this->compare($operator, $actual, $expected);

        $explanation[] = [
            'field' => $field,
            'label' => FieldRegistry::get($field)['label'] ?? $field,
            'operator' => $operator,
            'value' => $expected,
            'actual' => $actual,
            'passed' => $passed,
        ];

        return $passed;
    }

    private function compare(string $operator, mixed $actual, mixed $expected): bool
    {
        // Null means the feature was never computed for this symbol/date. Only
        // the null predicates treat that as answerable; every other comparison
        // against a missing value is false rather than silently coercing to 0.
        if ($operator === RuleOperators::IS_NULL) {
            return $actual === null;
        }

        if ($operator === RuleOperators::NOT_NULL) {
            return $actual !== null;
        }

        if ($actual === null) {
            return false;
        }

        if ($operator === RuleOperators::IS_TRUE) {
            return $this->toBool($actual);
        }

        if ($operator === RuleOperators::IS_FALSE) {
            return ! $this->toBool($actual);
        }

        if ($operator === RuleOperators::BETWEEN) {
            if (! is_array($expected) || count($expected) !== 2) {
                return false;
            }

            $value = (float) $actual;

            return $value >= (float) $expected[0] && $value <= (float) $expected[1];
        }

        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        $left = (float) $actual;
        $right = (float) $expected;

        return match ($operator) {
            RuleOperators::GT => $left > $right,
            RuleOperators::GTE => $left >= $right,
            RuleOperators::LT => $left < $right,
            RuleOperators::LTE => $left <= $right,
            // Float equality on computed indicators is never exact, so compare
            // within a small tolerance rather than with ===.
            RuleOperators::EQ => abs($left - $right) < 1e-9,
            RuleOperators::NEQ => abs($left - $right) >= 1e-9,
            default => false,
        };
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // SQLite hands booleans back as 0/1 integers and MariaDB as tinyint,
        // so normalise numerics rather than relying on PHP truthiness of "0".
        if (is_numeric($value)) {
            return (float) $value != 0.0;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
