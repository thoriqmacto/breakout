<?php

namespace App\Services\Strategy\Rules;

/**
 * Validates a user-supplied rule tree before it is stored or evaluated.
 *
 * The tree is recursive, so Laravel's array validation rules cannot express
 * it. Errors are collected with a dot path to the offending node so the UI can
 * point at the condition that is wrong rather than rejecting the whole tree
 * with one message.
 */
class RuleValidator
{
    /** Guards against a pathological or hand-crafted deeply nested tree. */
    public const MAX_DEPTH = 5;

    /** Total conditions across the whole tree. */
    public const MAX_CONDITIONS = 50;

    /** @var array<int, string> */
    private array $errors = [];

    private int $conditionCount = 0;

    /**
     * @param  mixed  $tree
     * @return array<int, string> Empty when the tree is valid.
     */
    public function validate($tree): array
    {
        $this->errors = [];
        $this->conditionCount = 0;

        if (! is_array($tree)) {
            return ['rules must be an object.'];
        }

        $this->walk($tree, 'rules', 1);

        if ($this->conditionCount === 0 && $this->errors === []) {
            $this->errors[] = 'rules must contain at least one condition.';
        }

        if ($this->conditionCount > self::MAX_CONDITIONS) {
            $this->errors[] = sprintf(
                'rules may contain at most %d conditions, got %d.',
                self::MAX_CONDITIONS,
                $this->conditionCount,
            );
        }

        return $this->errors;
    }

    /**
     * @param  array<mixed>  $node
     */
    private function walk(array $node, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->errors[] = "{$path}: nesting is deeper than ".self::MAX_DEPTH.' levels.';

            return;
        }

        // A group node carries a boolean operator and children; anything else
        // is treated as a leaf condition.
        if (array_key_exists('op', $node)) {
            $this->walkGroup($node, $path, $depth);

            return;
        }

        $this->walkCondition($node, $path);
    }

    /**
     * @param  array<mixed>  $node
     */
    private function walkGroup(array $node, string $path, int $depth): void
    {
        $op = is_string($node['op'] ?? null) ? strtolower($node['op']) : '';

        if (! in_array($op, ['and', 'or'], true)) {
            $this->errors[] = "{$path}.op must be 'and' or 'or'.";
        }

        $children = $node['rules'] ?? null;

        if (! is_array($children) || $children === []) {
            $this->errors[] = "{$path}.rules must be a non-empty array.";

            return;
        }

        foreach (array_values($children) as $index => $child) {
            if (! is_array($child)) {
                $this->errors[] = "{$path}.rules.{$index} must be an object.";

                continue;
            }

            $this->walk($child, "{$path}.rules.{$index}", $depth + 1);
        }
    }

    /**
     * @param  array<mixed>  $node
     */
    private function walkCondition(array $node, string $path): void
    {
        $this->conditionCount++;

        $field = is_string($node['field'] ?? null) ? $node['field'] : '';

        if ($field === '') {
            $this->errors[] = "{$path}.field is required.";

            return;
        }

        if (! FieldRegistry::has($field)) {
            $this->errors[] = "{$path}.field '{$field}' is not a known field.";

            return;
        }

        $operator = is_string($node['operator'] ?? null) ? strtolower($node['operator']) : '';
        $type = FieldRegistry::typeOf($field);
        $allowed = RuleOperators::forType($type ?? '');

        if (! in_array($operator, $allowed, true)) {
            $this->errors[] = sprintf(
                "%s.operator '%s' is not valid for a %s field. Allowed: %s.",
                $path,
                $operator,
                $type,
                implode(', ', $allowed),
            );

            return;
        }

        $this->validateValue($node, $path, $operator);
    }

    /**
     * @param  array<mixed>  $node
     */
    private function validateValue(array $node, string $path, string $operator): void
    {
        $arity = RuleOperators::arity($operator);

        if ($arity === 0) {
            return;
        }

        $value = $node['value'] ?? null;

        if ($arity === 2) {
            if (! is_array($value) || count($value) !== 2) {
                $this->errors[] = "{$path}.value must be an array of two numbers for '{$operator}'.";

                return;
            }

            foreach (array_values($value) as $index => $bound) {
                if (! is_numeric($bound)) {
                    $this->errors[] = "{$path}.value.{$index} must be numeric.";
                }
            }

            if (is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null)
                && (float) $value[0] > (float) $value[1]) {
                $this->errors[] = "{$path}.value lower bound must not exceed the upper bound.";
            }

            return;
        }

        if (! is_numeric($value)) {
            $this->errors[] = "{$path}.value must be numeric for '{$operator}'.";
        }
    }
}
