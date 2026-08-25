<?php

namespace Tests\Unit\Services;

use App\Services\Strategy\Rules\RuleEvaluator;
use App\Services\Strategy\Rules\RuleValidator;
use Tests\TestCase;

class StrategyRuleEngineTest extends TestCase
{
    private function evaluator(): RuleEvaluator
    {
        return new RuleEvaluator;
    }

    private function validator(): RuleValidator
    {
        return new RuleValidator;
    }

    public function test_and_group_requires_every_condition(): void
    {
        $tree = [
            'op' => 'and',
            'rules' => [
                ['field' => 'features.vol_ratio_20', 'operator' => 'gte', 'value' => 1.5],
                ['field' => 'features.breakout20', 'operator' => 'is_true'],
            ],
        ];

        $pass = $this->evaluator()->evaluate($tree, [
            'features.vol_ratio_20' => 2.0,
            'features.breakout20' => 1,
        ]);
        $this->assertTrue($pass['matched']);

        $fail = $this->evaluator()->evaluate($tree, [
            'features.vol_ratio_20' => 2.0,
            'features.breakout20' => 0,
        ]);
        $this->assertFalse($fail['matched']);
    }

    public function test_or_group_requires_only_one_condition(): void
    {
        $tree = [
            'op' => 'or',
            'rules' => [
                ['field' => 'features.vol_ratio_20', 'operator' => 'gte', 'value' => 5.0],
                ['field' => 'metrics.uptrend', 'operator' => 'is_true'],
            ],
        ];

        $result = $this->evaluator()->evaluate($tree, [
            'features.vol_ratio_20' => 1.0,
            'metrics.uptrend' => true,
        ]);

        $this->assertTrue($result['matched']);
    }

    public function test_nested_groups_compose(): void
    {
        $tree = [
            'op' => 'and',
            'rules' => [
                ['field' => 'features.has_broker', 'operator' => 'is_true'],
                [
                    'op' => 'or',
                    'rules' => [
                        ['field' => 'features.stealth_acc', 'operator' => 'is_true'],
                        ['field' => 'features.top3_net_norm', 'operator' => 'gte', 'value' => 0.4],
                    ],
                ],
            ],
        ];

        $row = [
            'features.has_broker' => 1,
            'features.stealth_acc' => 0,
            'features.top3_net_norm' => 0.55,
        ];

        $this->assertTrue($this->evaluator()->evaluate($tree, $row)['matched']);

        $row['features.top3_net_norm'] = 0.1;
        $this->assertFalse($this->evaluator()->evaluate($tree, $row)['matched']);
    }

    public function test_between_is_inclusive(): void
    {
        $tree = ['field' => 'features.atr_pct', 'operator' => 'between', 'value' => [2, 5]];

        foreach ([2, 3.5, 5] as $inside) {
            $this->assertTrue($this->evaluator()->evaluate($tree, ['features.atr_pct' => $inside])['matched']);
        }

        foreach ([1.99, 5.01] as $outside) {
            $this->assertFalse($this->evaluator()->evaluate($tree, ['features.atr_pct' => $outside])['matched']);
        }
    }

    /**
     * A missing feature must not be silently treated as zero -- that would
     * make "less than 1" match every symbol that was never computed.
     */
    public function test_null_values_fail_comparisons_but_answer_null_predicates(): void
    {
        $row = ['features.pbas' => null];

        $this->assertFalse(
            $this->evaluator()->evaluate(
                ['field' => 'features.pbas', 'operator' => 'lt', 'value' => 1],
                $row,
            )['matched']
        );

        $this->assertTrue(
            $this->evaluator()->evaluate(
                ['field' => 'features.pbas', 'operator' => 'is_null'],
                $row,
            )['matched']
        );

        $this->assertFalse(
            $this->evaluator()->evaluate(
                ['field' => 'features.pbas', 'operator' => 'not_null'],
                $row,
            )['matched']
        );
    }

    /**
     * SQLite returns booleans as 0/1 and MariaDB as tinyint, so is_true must
     * not depend on PHP's truthiness of the raw driver value.
     */
    public function test_boolean_coercion_across_driver_representations(): void
    {
        $tree = ['field' => 'features.breakout20', 'operator' => 'is_true'];

        foreach ([true, 1, '1', 1.0] as $truthy) {
            $this->assertTrue(
                $this->evaluator()->evaluate($tree, ['features.breakout20' => $truthy])['matched'],
                'expected true for '.var_export($truthy, true),
            );
        }

        foreach ([false, 0, '0', 0.0] as $falsy) {
            $this->assertFalse(
                $this->evaluator()->evaluate($tree, ['features.breakout20' => $falsy])['matched'],
                'expected false for '.var_export($falsy, true),
            );
        }
    }

    public function test_explanation_records_every_condition(): void
    {
        $tree = [
            'op' => 'and',
            'rules' => [
                ['field' => 'features.vol_ratio_20', 'operator' => 'gte', 'value' => 1.5],
                ['field' => 'features.breakout20', 'operator' => 'is_true'],
            ],
        ];

        $result = $this->evaluator()->evaluate($tree, [
            'features.vol_ratio_20' => 2.0,
            'features.breakout20' => 0,
        ]);

        $this->assertCount(2, $result['explanation']);
        $this->assertTrue($result['explanation'][0]['passed']);
        $this->assertFalse($result['explanation'][1]['passed']);
        $this->assertSame(2.0, $result['explanation'][0]['actual']);
    }

    public function test_validator_rejects_unknown_field(): void
    {
        $errors = $this->validator()->validate([
            'field' => 'features.definitely_not_a_column',
            'operator' => 'gt',
            'value' => 1,
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('is not a known field', $errors[0]);
    }

    public function test_validator_rejects_operator_wrong_for_field_type(): void
    {
        // breakout20 is boolean; "gte" is a numeric operator.
        $errors = $this->validator()->validate([
            'field' => 'features.breakout20',
            'operator' => 'gte',
            'value' => 1,
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('is not valid for a boolean field', $errors[0]);
    }

    public function test_validator_rejects_non_numeric_and_malformed_between(): void
    {
        $this->assertNotEmpty($this->validator()->validate([
            'field' => 'features.atr_pct', 'operator' => 'gt', 'value' => 'abc',
        ]));

        $this->assertNotEmpty($this->validator()->validate([
            'field' => 'features.atr_pct', 'operator' => 'between', 'value' => [5],
        ]));

        $inverted = $this->validator()->validate([
            'field' => 'features.atr_pct', 'operator' => 'between', 'value' => [9, 2],
        ]);
        $this->assertNotEmpty($inverted);
        $this->assertStringContainsString('lower bound', $inverted[0]);
    }

    public function test_validator_rejects_empty_group_and_excessive_depth(): void
    {
        $this->assertNotEmpty($this->validator()->validate(['op' => 'and', 'rules' => []]));

        $deep = ['field' => 'features.atr_pct', 'operator' => 'gt', 'value' => 1];
        for ($i = 0; $i < RuleValidator::MAX_DEPTH + 1; $i++) {
            $deep = ['op' => 'and', 'rules' => [$deep]];
        }

        $errors = $this->validator()->validate($deep);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('nesting is deeper', $errors[0]);
    }

    public function test_validator_accepts_a_well_formed_tree(): void
    {
        $errors = $this->validator()->validate([
            'op' => 'and',
            'rules' => [
                ['field' => 'features.vol_ratio_20', 'operator' => 'gte', 'value' => 1.5],
                ['field' => 'features.breakout20', 'operator' => 'is_true'],
                [
                    'op' => 'or',
                    'rules' => [
                        ['field' => 'metrics.close_vs_high20', 'operator' => 'between', 'value' => [0.9, 1.0]],
                        ['field' => 'features.pbas', 'operator' => 'not_null'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $errors);
    }
}
