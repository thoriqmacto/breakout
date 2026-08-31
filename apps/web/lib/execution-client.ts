import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

export type ExecutionStatus = "READY" | "WATCH" | "AVOID" | "STALE"

export const EXECUTION_STATUSES: ExecutionStatus[] = ["READY", "WATCH", "AVOID", "STALE"]

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
  disclaimer: string
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
