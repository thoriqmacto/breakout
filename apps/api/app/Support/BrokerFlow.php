<?php

namespace App\Support;

/**
 * The one reading of Stockbit's accumulation/distribution label.
 *
 * Stockbit returns `broker_accdist` as free text -- "Acc", "Dist",
 * "Accumulation", occasionally blank -- and every consumer that wants a number
 * out of it has to make the same three decisions: what counts as
 * accumulation, what counts as distribution, and what a value that is neither
 * means. FeatureExtractionService made them privately, which was fine until a
 * second consumer needed the same answer: two substring tests drifting apart
 * would make the strategy pipeline and the reconciliation layer disagree about
 * whether a given session accumulated, with nothing to say which was right.
 *
 * Deliberately substring matching rather than an exact list. The source is a
 * label, not an enum, and "Accumulation" and "Acc" mean the same thing; a
 * value that matches neither scores zero rather than guessing.
 */
final class BrokerFlow
{
    public const ACCUMULATION = 1;

    public const DISTRIBUTION = -1;

    public const NEUTRAL = 0;

    /**
     * +1 accumulating, -1 distributing, 0 neither or unknown.
     */
    public static function score(?string $label): int
    {
        $lower = strtolower(trim((string) $label));

        if ($lower === '') {
            return self::NEUTRAL;
        }

        if (str_contains($lower, 'acc')) {
            return self::ACCUMULATION;
        }

        if (str_contains($lower, 'dist')) {
            return self::DISTRIBUTION;
        }

        return self::NEUTRAL;
    }

    /**
     * A stable label for a score, for display and for bucketing.
     */
    public static function label(int $score): string
    {
        return match (true) {
            $score > 0 => 'Acc',
            $score < 0 => 'Dist',
            default => 'Neutral',
        };
    }

    /**
     * Sum of the scores over the most recent $sessions observations.
     *
     * The count of observations actually used is returned alongside the sum,
     * and that is the point of the method existing at all. A five-day balance
     * of 0 from five neutral sessions and one from no sessions at all are
     * completely different statements, and a bare integer cannot tell them
     * apart -- so callers get both and can say "insufficient data" rather than
     * "neutral".
     *
     * @param  array<int, int>  $scores  oldest first
     * @return array{balance: int, available: int}
     */
    public static function balance(array $scores, int $sessions): array
    {
        $slice = $sessions > 0 ? array_slice($scores, -$sessions) : [];

        return [
            'balance' => array_sum($slice),
            'available' => count($slice),
        ];
    }
}
