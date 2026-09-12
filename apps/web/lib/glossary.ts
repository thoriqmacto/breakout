/**
 * What every column on this dashboard actually means.
 *
 * One file rather than a `title` attribute per table. The same term appears on
 * the Assets table, the Execution workspace and the Watchlist, and three
 * copies of "Score" drifted into meaning three different things -- which is
 * most of why the pages read as though they disagree with each other.
 *
 * Definitions say what the number is and, where it matters, what it is *not*.
 * "Score" is the clearest case: it ranks a setup's quality and it is not a
 * gate on whether the setup is executable, so a high score with nothing
 * actionable is the ordinary case rather than a contradiction.
 *
 * `range` exists because a definition alone does not make a number readable.
 * One symbol showing PBAS 0 next to another showing 55 means nothing until you
 * know the scale runs 0..100 and that 0 is a floor a penalty can push into, not
 * "no data". Every range here was read out of the code that produces the
 * value; a column whose scale is open-ended (a price, a volume) has no range
 * rather than an invented one.
 */
export type GlossaryEntry = {
  /** The heading as it appears in the table. */
  term: string
  /** One or two sentences. Plain, and honest about limits. */
  definition: string
  /**
   * The scale the value is drawn on, when it has one that can be stated.
   * Thresholds quoted here are the shipped defaults and are configurable.
   */
  range?: string
}

export const GLOSSARY: Record<string, GlossaryEntry> = {
  // ---------------------------------------------------------------- Assets
  structuralRank: {
    term: "Structural rank",
    definition:
      "Position in the universe by structural strength: uptrend first, then 13-week momentum, then how close price sits to its own 55- and 20-week highs, then volume. It says how strong a stock is, not whether its current setup can be traded — that is the Execution workspace.",
    range: "1 is strongest, counting up to the number of ranked assets.",
  },
  symbol: {
    term: "Symbol",
    definition:
      "The IDX ticker. Click it for everything stored about that asset. A chip beside it — JII70 — means the stock is currently in that published index; click the chip for the whole constituent list and what has joined or left.",
    range: "Four letters, as the exchange lists them.",
  },
  name: { term: "Name", definition: "The listed company name." },
  close: {
    term: "Close",
    definition: "Closing price of the most recent bar held for this asset.",
    range: "Rupiah per share.",
  },
  ma50: {
    term: "MA 50",
    definition: "Average close over the last 50 sessions.",
    range: "Rupiah per share. Above the close means price is under its average.",
  },
  ma100: {
    term: "MA 100",
    definition: "Average close over the last 100 sessions.",
    range: "Rupiah per share.",
  },
  high20: {
    term: "20w High",
    definition:
      "Highest intraday high over the last 20 weeks (100 sessions). Not the same level as Brk20 on the Execution page, which is the high of the last 20 trading sessions.",
    range: "Rupiah per share. Always at or above the close.",
  },
  high55: {
    term: "55w High",
    definition: "Highest intraday high over the last 55 weeks (275 sessions).",
    range: "Rupiah per share. Always at or above the close.",
  },
  atr14: {
    term: "ATR 14d",
    definition:
      "Average True Range over 14 sessions: how far this stock typically moves in a day, in rupiah. Used to size stops and to measure how far price sits from a breakout level.",
    range: "Rupiah per share, never negative. Read it against the close: 50 on a 1,000 stock is a 5% daily range.",
  },
  roc13: {
    term: "ROC 13w",
    definition: "Rate of change over 13 weeks — momentum as a percentage.",
    range: "Unbounded percentage. 0% is unchanged over 13 weeks; negative is below where it was.",
  },
  avgVol20: {
    term: "Avg Vol 20d",
    definition: "Average traded volume over the last 20 sessions.",
    range: "Shares per session.",
  },
  volVsAvg20: {
    term: "Vol / Avg 20d",
    definition:
      "Today's volume as a multiple of the 20-session average. Above 1 means heavier trade than usual; a breakout on thin volume is worth less than one on heavy volume.",
    range: "0 upward. 1.00 is exactly average, 1.50 is half again as heavy. The watchlist calls 1.50 the confirmation threshold.",
  },
  closeVsHigh20: {
    term: "Close / 20wH",
    definition: "Close divided by the 20-week high.",
    range: "0 to 1.00. 1.00 means the close is the highest high of the window; 0.90 means 10% below it.",
  },
  closeVsHigh55: {
    term: "Close / 55wH",
    definition: "Close divided by the 55-week high.",
    range: "0 to 1.00, read the same way as Close / 20wH.",
  },
  uptrend: {
    term: "Uptrend",
    definition:
      "Whether the close is above the 150-session average. A structural filter, not a signal: plenty of uptrending stocks have no tradeable setup today.",
    range: "Yes or no.",
  },
  pbas: {
    term: "PBAS",
    definition:
      "Pre-Breakout Accumulation Score: how much this looks like quiet accumulation before a move — stealth broker buying, a compressed range, volume at or below average, price just above its 20-day mean. Penalties subtract from it, and a stock that has already broken out loses 20 points because the setup it measures has passed.",
    range:
      "0 to 100, whole numbers. 0 is a floor, not missing data: distribution (−40), a breakdown (−25) or an already-completed breakout can push the raw total below zero and it is clamped. 55 is a mid-strength setup; the highest score the rules can produce is 100.",
  },
  bavg: {
    term: "BAVG",
    definition:
      "Average price paid by accumulating brokers, with today's close as a percentage above or below it. Below their average can mean you are buying cheaper than they did.",
    range: "Rupiah per share, with the gap in percent. Negative means the close sits below the broker average.",
  },
  bars: {
    term: "Bars",
    definition: "How many daily price bars are stored for this asset.",
    range: "A count. Some columns need history: ROC 13w needs 66 bars, the 55-week high needs 275.",
  },
  lastBar: {
    term: "Last bar",
    definition: "Date of the newest bar held. If this is behind the market, collection has stalled for this symbol.",
  },
  sessionsMissing: {
    term: "Gaps",
    definition:
      "Sessions the market held inside this asset's own history that have no bar. Counted only between its first and last bar — an asset listed last month is not missing the years before it existed.",
    range: "A count of sessions. 0 is the healthy value.",
  },
  sessionsBehind: {
    term: "Behind",
    definition:
      "Sessions the market has held since this asset's last bar. Different from Gaps: a hole in the middle and a feed that stopped updating need different fixes.",
    range: "A count of sessions. 0 is current; 1 is normal before the evening collection runs.",
  },

  // ------------------------------------------------------------- Execution
  executionRank: {
    term: "Exec",
    definition:
      "Rank by how actionable the setup is for the next session. Unrelated to structural rank: a structurally excellent stock with no entry plan ranks poorly here, and that is the normal case.",
    range: "1 is the most actionable candidate on this list.",
  },
  executionStatus: {
    term: "Status",
    definition:
      "What the rules say about this setup on the last completed session. TRIGGERED and ARMED are the two that mean something may happen next session; the rest say why nothing will.",
    range:
      "TRIGGERED · ARMED · NO_CHASE · WATCH · AVOID · STALE for candidates, and HOLD · TRAILING · EXIT for a symbol the portfolio already owns.",
  },
  executionAction: {
    term: "Action",
    definition: "The one thing the rules suggest doing next session — or explicitly not doing.",
  },
  executionScore: {
    term: "Score",
    definition:
      "Setup quality out of 100, weighted across broker persistence, strength and acceleration, breakout and volume confirmation, trend quality, liquidity, risk quality and the historical outcome rate. It ranks candidates against each other. It does NOT decide the status: a high score with no breakout and no accumulation is still WATCH, which is why a strong score and an empty ARMED list are consistent rather than contradictory.",
    range: "0 to 100. The workspace filter defaults to showing 75 and above. A component with too thin a sample scores a neutral 50 rather than 0.",
  },
  brokerRegime: {
    term: "Regime",
    definition:
      "Broker flow read across the 5, 10 and 20-day windows: accumulation, neutral or distribution. ARMED requires accumulation; distribution disqualifies a setup outright.",
    range:
      "Five states: STRONG_ACCUMULATION, ACCUMULATION, NEUTRAL, DISTRIBUTION, STRONG_DISTRIBUTION.",
  },
  brokerFlow: {
    term: "Flow",
    definition: "Direction of broker net flow over 3, 5, 10 and 20 days, newest first.",
    range: "One mark per window: accumulating, flat, or distributing. A window with no rollup reads flat.",
  },
  brokerAgreement: {
    term: "Persist",
    definition:
      "How many broker windows point the same way. Low agreement means a mixed picture — one hot session rather than sustained buying.",
    range: "0/N to N/N, where N is how many of the 3, 5, 10 and 20-day windows have data (at most 4).",
  },
  breakout20: {
    term: "Brk20",
    definition:
      "Whether the last session closed above the highest high of the 20 sessions before it. This, not the score, is what separates TRIGGERED from ARMED. A double mark means it also cleared the 55-session high.",
    range: "✓ 20-session breakout · ✓✓ 55-session breakout · — neither.",
  },
  trigger: {
    term: "Trigger",
    definition: "The price at which the plan says to enter. Everything below is measured here, not at the close.",
    range: "Rupiah per share.",
  },
  entryZone: {
    term: "Zone",
    definition:
      "Trigger to trigger plus an ATR extension. Above the zone the setup becomes NO_CHASE: the move was real and you have missed the entry.",
    range: "A rupiah band starting at the trigger.",
  },
  stop: {
    term: "Stop",
    definition: "The invalidation level. If there is no measurable one, no risk can be sized and the setup is AVOID.",
    range: "Rupiah per share, always below the trigger.",
  },
  riskPct: {
    term: "Risk %",
    definition: "Distance from trigger to stop, as a percentage of the trigger.",
    range: "Positive percentage. Smaller is a tighter stop, which is not automatically better — a stop inside the noise gets hit.",
  },
  riskReward: {
    term: "R/R",
    definition:
      "Reward over risk measured at the trigger, not at the close. A setup can look fine on the screen and fail here — that is the check that catches it.",
    range: "A ratio. 2.00 means twice the reward for the risk; the default execution filter rejects anything below 2.00.",
  },
  trailActivation: {
    term: "Trail",
    definition: "The price (+5% by default) at which the trailing stop activates on an open position.",
    range: "Rupiah per share, set at the default +5% above entry. It is an activation level, never a profit target.",
  },
  profitFloor: {
    term: "Floor",
    definition: "The locked-in profit level (+3% by default) the trailing stop will not fall below once activated.",
    range: "Rupiah per share, at the default +3% above entry.",
  },
  probability: {
    term: "P(+5%)",
    definition:
      "Among comparable historical setups, the share that reached +5% before hitting their initial stop. An empirical statistic over past cases, not a prediction and not a guarantee about this one.",
    range: "0% to 100%, and blank until the comparable sample reaches the minimum (30 setups by default).",
  },
  sampleSize: {
    term: "n",
    definition: "How many comparable historical setups that probability is drawn from. A small n means a weak estimate.",
    range: "A count. Below 30 (the default minimum) no rate is shown at all.",
  },
  held: {
    term: "Held",
    definition: "Whether the selected portfolio already holds this symbol.",
    range: "Yes or no.",
  },

  // ------------------------------------------------------------- Watchlist
  watchlistScore: {
    term: "Score",
    definition:
      "Ranking score for the scan date: 45% broker accumulation (BAS), 35% breakout confirmation (BCS) and 20% the two filters. It orders candidates; it does not decide whether one is executable. Open Execution for that.",
    range: "0 to 100. Both filters failing costs 20 points outright, so a strong setup that cannot be traded still scores below 80.",
  },
  bas: {
    term: "BAS",
    definition:
      "Broker Accumulation Score: how strongly brokers have been net buying, averaged across windows and weighted toward the longer ones so a single hot day cannot dominate.",
    range: "0 to 100, where 50 is neutral flow. Same-day PBAS nudges it by 15%.",
  },
  bcs: {
    term: "BCS",
    definition:
      "Breakout Confirmation Score: built from three checks — a close above the prior 20-day high (50), volume at 1.5× its average (25, or half for merely above average), and a close in the top 30% of the day's range (25).",
    range: "0 to 100, in steps: 0, 12.5, 25, 37.5, 50, 62.5, 75, 87.5, 100.",
  },
  liquidityFilter: {
    term: "LF",
    definition:
      "Liquidity filter. Fails when the stock does not trade enough for a position to be entered and exited at a sane price.",
    range: "Pass or fail. Defaults: at least Rp5B turnover and at least 5 active brokers.",
  },
  riskRewardFilter: {
    term: "RRF",
    definition:
      "Risk/reward filter at the signal close. Passing here is not the same as passing at the entry trigger, which is the stricter test Execution applies.",
    range: "Pass or fail, at a default minimum of 2.00.",
  },
  target: {
    term: "Target",
    definition: "The measured objective used to compute risk/reward. Not a price prediction.",
    range: "Rupiah per share.",
  },
  volumeRatio: {
    term: "VolR",
    definition: "Volume relative to its recent average on the scan date.",
    range: "0 upward. 1.00 is average; BCS pays full credit from 1.50.",
  },
  why: {
    term: "Why",
    definition: "The conditions that produced this row — expand to see what passed and what did not.",
  },
}

/** The definition for a term id, or null when nothing is registered. */
export function glossary(id: string): GlossaryEntry | null {
  return GLOSSARY[id] ?? null
}
