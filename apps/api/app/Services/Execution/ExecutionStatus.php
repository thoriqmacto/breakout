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

    public const ALL = [self::READY, self::WATCH, self::AVOID, self::STALE];
}
