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
 */
export type GlossaryEntry = {
  /** The heading as it appears in the table. */
  term: string
  /** One or two sentences. Plain, and honest about limits. */
  definition: string
}

export const GLOSSARY: Record<string, GlossaryEntry> = {
  // ---------------------------------------------------------------- Assets
  structuralRank: {
    term: "Structural rank",
    definition:
      "Position in the universe by structural strength: trend first, then momentum and how close price sits to its own 20- and 55-week highs. It says how strong a stock is, not whether its current setup can be traded — that is the Execution workspace.",
  },
  symbol: {
    term: "Symbol",
    definition: "The IDX ticker. Click it for everything stored about that asset.",
  },
  name: { term: "Name", definition: "The listed company name." },
  close: {
    term: "Close",
    definition: "Closing price of the most recent bar held for this asset.",
  },
  ma50: { term: "MA 50", definition: "Average close over the last 50 sessions." },
  ma100: { term: "MA 100", definition: "Average close over the last 100 sessions." },
  high20: {
    term: "20w High",
    definition: "Highest close over the last 20 weeks — the level a 20-session breakout has to clear.",
  },
  high55: { term: "55w High", definition: "Highest close over the last 55 weeks." },
  atr14: {
    term: "ATR 14d",
    definition:
      "Average True Range over 14 sessions: how far this stock typically moves in a day, in rupiah. Used to size stops and to measure how far price sits from a breakout level.",
  },
  roc13: {
    term: "ROC 13w",
    definition: "Rate of change over 13 weeks — momentum as a percentage.",
  },
  avgVol20: { term: "Avg Vol 20d", definition: "Average traded volume over the last 20 sessions." },
  volVsAvg20: {
    term: "Vol / Avg 20d",
    definition:
      "Today's volume as a multiple of the 20-session average. Above 1 means heavier trade than usual; a breakout on thin volume is worth less than one on heavy volume.",
  },
  closeVsHigh20: {
    term: "Close / 20wH",
    definition: "Close divided by the 20-week high. 1.00 means price is sitting at that high.",
  },
  closeVsHigh55: {
    term: "Close / 55wH",
    definition: "Close divided by the 55-week high.",
  },
  uptrend: {
    term: "Uptrend",
    definition:
      "Whether the close is above the long trend average. A structural filter, not a signal: plenty of uptrending stocks have no tradeable setup today.",
  },
  pbas: {
    term: "PBAS",
    definition:
      "Broker accumulation score from the bandar detector — how concentrated net buying has been among brokers.",
  },
  bavg: {
    term: "BAVG",
    definition:
      "Average price paid by accumulating brokers, with today's close as a percentage above or below it. Below their average can mean you are buying cheaper than they did.",
  },
  bars: {
    term: "Bars",
    definition: "How many daily price bars are stored for this asset.",
  },
  lastBar: {
    term: "Last bar",
    definition: "Date of the newest bar held. If this is behind the market, collection has stalled for this symbol.",
  },
  sessionsMissing: {
    term: "Gaps",
    definition:
      "Sessions the market held inside this asset's own history that have no bar. Counted only between its first and last bar — an asset listed last month is not missing the years before it existed.",
  },
  sessionsBehind: {
    term: "Behind",
    definition:
      "Sessions the market has held since this asset's last bar. Different from Gaps: a hole in the middle and a feed that stopped updating need different fixes.",
  },

  // ------------------------------------------------------------- Execution
  executionRank: {
    term: "Exec",
    definition:
      "Rank by how actionable the setup is for the next session. Unrelated to structural rank: a structurally excellent stock with no entry plan ranks poorly here, and that is the normal case.",
  },
  executionStatus: {
    term: "Status",
    definition:
      "What the rules say about this setup on the last completed session. TRIGGERED and ARMED are the two that mean something may happen next session; the rest say why nothing will.",
  },
  executionAction: {
    term: "Action",
    definition: "The one thing the rules suggest doing next session — or explicitly not doing.",
  },
  executionScore: {
    term: "Score",
    definition:
      "Setup quality out of 100, from broker flow, price structure and risk. It ranks candidates against each other. It does NOT decide the status: a high score with no breakout and no accumulation is still WATCH, which is why a strong score and an empty ARMED list are consistent rather than contradictory.",
  },
  brokerRegime: {
    term: "Regime",
    definition:
      "Broker flow read across the 5, 10 and 20-day windows: accumulation, neutral or distribution. ARMED requires accumulation; distribution disqualifies a setup outright.",
  },
  brokerFlow: {
    term: "Flow",
    definition: "Direction of broker net flow over 3, 5, 10 and 20 days, newest first.",
  },
  brokerAgreement: {
    term: "Agree",
    definition: "The fraction of those broker windows pointing the same way. Low agreement means a mixed picture.",
  },
  breakout20: {
    term: "Brk20",
    definition:
      "Whether the last session closed above the 20-session high. This, not the score, is what separates TRIGGERED from ARMED.",
  },
  trigger: {
    term: "Trigger",
    definition: "The price at which the plan says to enter. Everything below is measured here, not at the close.",
  },
  entryZone: {
    term: "Zone",
    definition:
      "Trigger to trigger plus an ATR extension. Above the zone the setup becomes NO_CHASE: the move was real and you have missed the entry.",
  },
  stop: {
    term: "Stop",
    definition: "The invalidation level. If there is no measurable one, no risk can be sized and the setup is AVOID.",
  },
  riskPct: {
    term: "Risk %",
    definition: "Distance from trigger to stop, as a percentage of the trigger.",
  },
  riskReward: {
    term: "R/R",
    definition:
      "Reward over risk measured at the trigger, not at the close. A setup can look fine on the screen and fail here — that is the check that catches it.",
  },
  trailActivation: {
    term: "Trail",
    definition: "The price (+5%) at which the trailing stop activates on an open position.",
  },
  profitFloor: {
    term: "Floor",
    definition: "The locked-in profit level (+3%) the trailing stop will not fall below once activated.",
  },
  probability: {
    term: "P(+5%)",
    definition:
      "Among comparable historical setups, the share that reached +5% before hitting their initial stop. An empirical statistic over past cases, not a prediction and not a guarantee about this one.",
  },
  sampleSize: {
    term: "n",
    definition: "How many comparable historical setups that probability is drawn from. A small n means a weak estimate.",
  },
  held: {
    term: "Held",
    definition: "Whether the selected portfolio already holds this symbol.",
  },

  // ------------------------------------------------------------- Watchlist
  watchlistScore: {
    term: "Score",
    definition:
      "Ranking score for the scan date, from broker accumulation, price structure and the risk filters. It orders candidates; it does not decide whether one is executable. Open Execution for that.",
  },
  bas: {
    term: "BAS",
    definition: "Broker Accumulation Score: how strongly brokers have been net buying.",
  },
  bcs: {
    term: "BCS",
    definition: "Broker Concentration Score: whether that buying is concentrated in few brokers or spread thin.",
  },
  liquidityFilter: {
    term: "LF",
    definition:
      "Liquidity filter. Fails when the stock does not trade enough for a position to be entered and exited at a sane price.",
  },
  riskRewardFilter: {
    term: "RRF",
    definition:
      "Risk/reward filter at the signal close. Passing here is not the same as passing at the entry trigger, which is the stricter test Execution applies.",
  },
  target: {
    term: "Target",
    definition: "The measured objective used to compute risk/reward. Not a price prediction.",
  },
  volumeRatio: {
    term: "VolR",
    definition: "Volume relative to its recent average on the scan date.",
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
