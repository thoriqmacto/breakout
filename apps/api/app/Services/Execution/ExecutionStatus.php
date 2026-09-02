<?php

namespace App\Services\Execution;

/**
 * The four things a candidate can be, and the only place the rules live.
 *
 * Rule-based and stated in full, because the user has to be able to disagree
 * with a status. Nothing here is learned, fitted or predicted: each status is
 * a conjunction of conditions that either held on the last completed session
 * or did not, and every candidate carries the list of the ones that decided it.
 */
final class ExecutionStatus
{
    /**
     * The signal is from the latest completed session, the setup is a valid
     * long, both filters pass, the score clears the configured minimum, and
     * the risk/reward still clears its minimum *at the planned entry trigger*
     * rather than merely at the signal close.
     */
    public const READY = 'READY';

    /**
     * A setup worth following that is not executable on the stated rules --
     * typically accumulation without breakout confirmation, or a score below
     * the threshold. Not a weaker READY: it has no entry plan attached.
     */
    public const WATCH = 'WATCH';

    /**
     * Something disqualifying: hard distribution, an invalid long setup, or no
     * measurable invalidation level. Kept visible rather than filtered away,
     * because "why is this not here" is a question worth being able to answer.
     */
    public const AVOID = 'AVOID';

    /**
     * The inputs are not from the latest completed session. Says so instead of
     * presenting yesterday's plan as today's.
     */
    public const STALE = 'STALE';

    /**
     * Broker accumulation is attractive and price sits within
     * `armed_distance_atr` of its breakout level, but has not cleared it.
     *
     * The state WATCH used to swallow. "Interesting eventually" and "one
     * ordinary session from triggering" are worth different amounts of
     * attention, and a single label for both makes the list unreadable on the
     * one morning it matters.
     */
    public const ARMED = 'ARMED';

    /**
     * Breakout confirmed on the signal session and actionable next session.
     *
     * The lifecycle successor to READY, which it replaces for candidates
     * scored under a v2 profile. READY remains for v1 rows so stored history
     * and the existing API contract keep their meaning.
     */
    public const TRIGGERED = 'TRIGGERED';

    /**
     * Triggered, but the next session's price has already run past the entry
     * zone. Deliberately its own state rather than a filtered-out row: the
     * setup was real and the reader should see that they missed it, not
     * wonder where it went.
     */
    public const NO_CHASE = 'NO_CHASE';

    /** The portfolio already holds this, below the trailing activation level. */
    public const HOLD = 'HOLD';

    /** Held, above activation: the trailing stop is live. */
    public const TRAILING = 'TRAILING';

    /** An exit condition has been met on an open position. */
    public const EXIT = 'EXIT';

    /**
     * Broker or price data is not current enough for the row to be acted on.
     *
     * Distinct from STALE, which says the *signal* is from an older session.
     * This one says the signal is current and its inputs are not -- most often
     * broker data that has not caught up with the latest completed session.
     */
    public const STALE_DATA = 'STALE_DATA';

    /**
     * The v1 statuses, unchanged, plus the lifecycle states.
     *
     * Order matters to the interface: this is the sequence a candidate moves
     * through, so a list grouped by status reads as a pipeline rather than an
     * alphabet.
     */
    public const ALL = [
        self::WATCH,
        self::ARMED,
        self::TRIGGERED,
        self::READY,
        self::NO_CHASE,
        self::HOLD,
        self::TRAILING,
        self::EXIT,
        self::AVOID,
        self::STALE,
        self::STALE_DATA,
    ];

    /** States that describe a position the portfolio already holds. */
    public const POSITION_STATES = [self::HOLD, self::TRAILING, self::EXIT];

    /** States where an entry may still be placed. */
    public const ACTIONABLE_STATES = [self::TRIGGERED, self::READY];

    public static function isPositionState(string $status): bool
    {
        return in_array($status, self::POSITION_STATES, true);
    }
}
