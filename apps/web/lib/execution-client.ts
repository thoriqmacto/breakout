import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

export type ExecutionStatus =
  | "WATCH"
  | "ARMED"
  | "TRIGGERED"
  | "READY"
  | "NO_CHASE"
  | "HOLD"
  | "TRAILING"
  | "EXIT"
  | "AVOID"
  | "STALE"
  | "STALE_DATA"

/**
 * In lifecycle order, not alphabetical: a list grouped by status should read
 * as the pipeline a candidate moves through.
 */
export const EXECUTION_STATUSES: ExecutionStatus[] = [
  "WATCH",
  "ARMED",
  "TRIGGERED",
  "READY",
  "NO_CHASE",
  "HOLD",
  "TRAILING",
  "EXIT",
  "AVOID",
  "STALE",
  "STALE_DATA",
]

export type PositionAction =
  | "WATCH"
  | "WAIT_FOR_BREAKOUT"
  | "BUY_ON_TRIGGER"
  | "NO_CHASE"
  | "HOLD"
  | "HOLD_TIGHTEN_STOP"
  | "TRAILING_ACTIVE"
  | "EXIT_WARNING"
  | "EXIT_TRIGGERED"
  | "STALE_DATA"
  | "AVOID"

export type BrokerRegime =
  | "STRONG_ACCUMULATION"
  | "ACCUMULATION"
  | "NEUTRAL"
  | "DISTRIBUTION"
  | "STRONG_DISTRIBUTION"

export type BrokerWindowFlow = {
  window_days: number
  avg_net_norm: number
  top3_net_norm: number
  accdist_score: number
  broker_count: number
  covered_days: number | null
  direction: number
}

export type ExecutionBrokerBlock = {
  regime: BrokerRegime
  persistence_ratio: number | null
  positive_windows: number
  negative_windows: number
  available_windows: number
  acceleration: number | null
  avg_net_norm: number | null
  top3_net_norm: number | null
  consistency: number | null
  active_brokers: number | null
  concentration: number | null
  pbas: number | null
  window_end_date: string | null
  reasons: string[]
} & Partial<Record<`flow_${number}d`, BrokerWindowFlow>>

export type ExecutionPriceSetup = {
  breakout20: boolean
  breakout55: boolean
  vol_ratio_20: number | null
  close_position: number | null
  atr14: number | null
  ema20: number | null
  ema50: number | null
  ema_aligned: boolean | null
  above_ema20: boolean | null
  prior_high20: number | null
  prior_high55: number | null
  distance_to_breakout_atr: number | null
  distance_to_breakout_pct: number | null
  compression: boolean | null
  gap_pct: number | null
  swing_low20: number | null
}

export type ExecutionPlanBlock = {
  valid: boolean
  rejected_reason: string | null
  breakout_level: number | null
  trigger_price: number | null
  entry_zone_low: number | null
  entry_zone_high: number | null
  entry_zone_atr: number | null
  initial_stop: number | null
  initial_stop_source: string | null
  risk_per_share: number | null
  initial_risk_pct: number | null
  max_initial_risk_pct: number | null
  reference_price: number
  reference_source: string
  entry_zone_state: "below" | "inside" | "above" | "unknown"
  entry_zone_reason: string
  extension_atr: number | null
  extension_pct: number | null
  notes: string[]
}

export type ExecutionPositionBlock = {
  qty_shares: number
  entry_price: number
  opened_at: string | null
  current_gain_pct: number | null
  highest_price_since_entry: number | null
  trailing_active: boolean
  trailing_activated_at: string | null
  trailing_activation_price: number | null
  distance_to_activation_pct: number | null
  profit_floor_price: number | null
  trailing_stop_price: number | null
  initial_stop_price: number | null
  effective_stop_price: number | null
  locked_profit_pct: number | null
  stop_updated_at: string | null
  evaluated_through: string | null
  latest_action: PositionAction | null
  latest_reasons: string[]
  strategy_version: string
}

export type ExecutionProfitManagement = {
  activation_gain_pct: number
  activation_price: number | null
  trailing_distance_pct: number
  minimum_locked_profit_pct: number
  profit_floor_price: number | null
  round_trip_cost_pct: number | null
  position: ExecutionPositionBlock | null
}

export type ExecutionHistoricalOutcome = {
  status: "OK" | "INSUFFICIENT_SAMPLE"
  match: "exact" | "coarse" | null
  bucket: string
  bucket_label: string
  sample_size: number
  exact_sample_size: number
  minimum_sample: number
  probability_hit_5_before_stop: number | null
  median_days_to_5: number | null
  median_mae_pct: number | null
  median_mfe_pct: number | null
  median_trailing_exit_return_pct: number | null
  win_rate: number | null
  expectancy_pct: number | null
  profit_factor: number | null
  median_hold_sessions: number | null
  strategy_version: string
}

export type ExecutionDataQuality = {
  broker_current: boolean
  ohlcv_current: boolean
  broker_window_to: string | null
  broker_lag_days: number | null
  max_broker_lag_days: number
  price_date: string
  latest_session: string | null
  reasons: string[]
}

export type ExecutionScoreComponent = {
  value: number
  weight: number
  contribution: number
  reason: string
}

export type ExecutionReasons = {
  broker: string[]
  price: string[]
  risk: string[]
  history: string[]
}

export type ExecutionTopBroker = {
  broker: string
  net_value: number
  from?: string | null
  to?: string | null
}

export type ExecutionHolding = {
  qty: number
  avg_cost: number
}

export type ExecutionDataFreshness = {
  price_date: string | null
  feature_date: string | null
  broker_window_from: string | null
  broker_window_to: string | null
}

export type ExecutionCandidate = {
  asset_id: number
  symbol: string
  name: string | null
  sector: string | null

  signal_date: string
  data_freshness: ExecutionDataFreshness

  execution_rank: number | null
  execution_score: number
  execution_status: ExecutionStatus
  status_reasons: string[]

  structural_rank: number | null
  uptrend: boolean
  roc13: number | null
  close_vs_high20: number | null
  close_vs_high55: number | null
  ma50: number | null
  ma100: number | null
  ma150: number | null
  atr14: number | null

  pbas: number | null
  bas: number
  bcs: number

  liquidity_pass: boolean
  risk_reward_pass: boolean
  valid_long_setup: boolean | null
  hard_distribution: boolean

  breakout20: boolean
  close_pos: number | null
  vol_ratio_20: number | null

  signal_open: number
  signal_high: number
  signal_low: number
  signal_close: number

  bavg: number | null
  distance_from_bavg_pct: number | null

  planned_entry_trigger: number | null
  planned_entry_reason: string
  planned_stop: number | null
  planned_target: number | null
  planned_risk_per_share: number | null
  planned_risk_reward: number | null
  signal_close_risk_reward: number | null

  top_brokers: ExecutionTopBroker[]
  reasons: string[]
  risk_notes: string | null
  warnings: string[]

  previous_execution_rank: number | null
  execution_rank_change_1d: number | null
  execution_score_change_1d: number | null
  ready_streak: number
  watch_streak: number

  holding: ExecutionHolding | null

  strategy_version: string
  lifecycle_status: ExecutionStatus
  action: PositionAction
  action_reasons: string[]

  execution_score_v2: number
  score_components: Record<string, ExecutionScoreComponent>
  reasons_v2: ExecutionReasons

  broker: ExecutionBrokerBlock
  price_setup: ExecutionPriceSetup
  execution_plan: ExecutionPlanBlock
  profit_management: ExecutionProfitManagement
  historical_outcome: ExecutionHistoricalOutcome
  data_quality: ExecutionDataQuality
  setup_bucket: string | null
  setup_bucket_label: string | null
}

export type ExecutionPayload = {
  signal_date: string | null
  next_trading_date: string | null
  version: string
  thresholds: {
    min_score: number
    min_rr: number
    max_entry_gap_pct: number | null
  }
  freshness: {
    signal_date: string | null
    latest_price_date: string | null
    latest_feature_date: string | null
    latest_broker_window_date: string | null
    signal_is_latest_session: boolean
  }
  counts: Record<string, number>
  total: number
  rows: ExecutionCandidate[]
  strategy_profile: {
    version: string
    broker_windows: number[]
    trail_activation_gain_pct: number
    trailing_distance_pct: number
    minimum_locked_profit_pct: number
    max_entry_extension_atr: number
    max_initial_risk_pct: number
    minimum_probability_sample: number
    intraday_assumption: string
    [key: string]: unknown
  }
  costs: {
    buy_fee_pct: number
    sell_fee_pct: number
    slippage_pct: number
    round_to_tick: boolean
  }
  disclaimer: string
  outcome_disclaimer: string
}

export type ExecutionFilters = {
  date?: string
  version?: string
  statuses?: ExecutionStatus[]
  symbols?: string[]
  sector?: string
  minScore?: number
  minRr?: number
  portfolioId?: number
  limit?: number
}

function buildHeaders(accessToken: string): HeadersInit {
  return {
    Accept: "application/json",
    Authorization: `Bearer ${accessToken}`,
  }
}

function extractErrorMessage(payload: ApiResponse<unknown> | null, fallback: string): string {
  if (payload && payload.status === "error" && payload.message) {
    return typeof payload.message === "string" ? payload.message : fallback
  }
  return fallback
}

export async function fetchExecutionCandidates(
  accessToken: string,
  filters: ExecutionFilters = {},
): Promise<ExecutionPayload> {
  const params = new URLSearchParams()

  if (filters.date) params.set("date", filters.date)
  if (filters.version) params.set("version", filters.version)
  if (filters.sector) params.set("sector", filters.sector)
  if (filters.minScore !== undefined) params.set("min_score", String(filters.minScore))
  if (filters.minRr !== undefined) params.set("min_rr", String(filters.minRr))
  if (filters.portfolioId !== undefined) params.set("portfolio_id", String(filters.portfolioId))
  if (filters.limit !== undefined) params.set("limit", String(filters.limit))

  for (const status of filters.statuses ?? []) {
    params.append("status[]", status)
  }

  for (const symbol of filters.symbols ?? []) {
    params.append("symbol[]", symbol.toUpperCase())
  }

  const query = params.toString()
  const url = buildApiUrl(`/v1/execution/candidates${query ? `?${query}` : ""}`)

  const response = await fetch(url, { method: "GET", headers: buildHeaders(accessToken) })
  const payload = await parseJson<ApiResponse<ExecutionPayload>>(response)

  if (!response.ok || !payload || payload.status !== "success" || !payload.data) {
    throw new Error(extractErrorMessage(payload, "Unable to load execution candidates."))
  }

  return payload.data
}
