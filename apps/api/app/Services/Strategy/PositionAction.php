<?php

namespace App\Services\Strategy;

/**
 * What the workspace suggests doing about a candidate or a holding.
 *
 * Separate from ExecutionStatus on purpose. The status describes the state
 * the world is in; the action is the one line the reader acts on. They are
 * usually derivable from one another and deliberately not merged, because a
 * status is an observation and an action is a recommendation, and collapsing
 * the two makes it impossible to show the observation without implying the
 * recommendation.
 *
 * Nothing here is advice. Every action is the stated consequence of rules the
 * user can read, disagree with, and change in the profile.
 */
final class PositionAction
{
    /** Broker flow is interesting; the price setup is not ready. */
    public const WATCH = 'WATCH';

    /** Setup is forming and price is near the level, but has not cleared it. */
    public const WAIT_FOR_BREAKOUT = 'WAIT_FOR_BREAKOUT';

    /** Breakout confirmed; actionable next session inside the entry zone. */
    public const BUY_ON_TRIGGER = 'BUY_ON_TRIGGER';

    /** Price has run beyond the entry zone. The setup may be fine; the entry is not. */
    public const NO_CHASE = 'NO_CHASE';

    /** Held, behaving, below the trailing activation level. */
    public const HOLD = 'HOLD';

    /** Held, but broker flow has deteriorated enough to justify less room. */
    public const HOLD_TIGHTEN_STOP = 'HOLD_TIGHTEN_STOP';

    /** Held above activation: the trailing stop is live and rising. */
    public const TRAILING_ACTIVE = 'TRAILING_ACTIVE';

    /** Broker flow says distribution while the position is still open. */
    public const EXIT_WARNING = 'EXIT_WARNING';

    /** An exit condition has been met. */
    public const EXIT_TRIGGERED = 'EXIT_TRIGGERED';

    /** Required broker or price data is not current enough to act on. */
    public const STALE_DATA = 'STALE_DATA';

    /** Something disqualifying about the setup itself. */
    public const AVOID = 'AVOID';

    public const ALL = [
        self::WATCH,
        self::WAIT_FOR_BREAKOUT,
        self::BUY_ON_TRIGGER,
        self::NO_CHASE,
        self::HOLD,
        self::HOLD_TIGHTEN_STOP,
        self::TRAILING_ACTIVE,
        self::EXIT_WARNING,
        self::EXIT_TRIGGERED,
        self::STALE_DATA,
        self::AVOID,
    ];
}
