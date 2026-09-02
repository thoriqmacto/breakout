<?php

namespace App\Services\Strategy;

/**
 * How broker flow is behaving over the medium term, as one label.
 *
 * Five states rather than a number, because the decision this feeds is
 * categorical: a regime either supports taking the trade or it does not, and
 * a 0..100 "accumulation score" invites the reader to split hairs over two
 * points that mean nothing.
 *
 * The classification is deterministic and derived only from windows that
 * ended on or before the session being scored. It is never fitted, and it
 * never consults the short window alone -- one strong day is an event, not a
 * regime, and letting 3D decide is precisely how a single spike outranks
 * months of steady accumulation.
 */
final class BrokerRegime
{
    /** Every medium-term window positive, and the buying concentrated. */
    public const STRONG_ACCUMULATION = 'STRONG_ACCUMULATION';

    /** Medium-term flow net positive, without the concentration. */
    public const ACCUMULATION = 'ACCUMULATION';

    /** No consistent direction, or too little data to claim one. */
    public const NEUTRAL = 'NEUTRAL';

    /** Medium-term flow net negative. */
    public const DISTRIBUTION = 'DISTRIBUTION';

    /** Every medium-term window negative, and the selling concentrated. */
    public const STRONG_DISTRIBUTION = 'STRONG_DISTRIBUTION';

    public const ALL = [
        self::STRONG_ACCUMULATION,
        self::ACCUMULATION,
        self::NEUTRAL,
        self::DISTRIBUTION,
        self::STRONG_DISTRIBUTION,
    ];

    /**
     * Ordering from most accumulative to most distributive, for ranking and
     * for comparing two regimes without a chain of string equality tests.
     */
    public const RANK = [
        self::STRONG_ACCUMULATION => 2,
        self::ACCUMULATION => 1,
        self::NEUTRAL => 0,
        self::DISTRIBUTION => -1,
        self::STRONG_DISTRIBUTION => -2,
    ];

    public static function rank(string $regime): int
    {
        return self::RANK[$regime] ?? 0;
    }

    public static function isAccumulative(string $regime): bool
    {
        return self::rank($regime) > 0;
    }

    public static function isDistributive(string $regime): bool
    {
        return self::rank($regime) < 0;
    }
}
