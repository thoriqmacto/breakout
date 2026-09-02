import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import type { IntegrityStatus } from "@/lib/backup-client"

/**
 * The reconciliation layer, read from the manifest.
 *
 * The list endpoint opens no asset documents at all -- it answers from the
 * single manifest -- so a universe of several hundred symbols costs one read.
 * A document is opened only when the reader expands one asset.
 */
export type ReconciliationSort =
  | "symbol"
  | "integrity_status"
  | "ohlcv_last"
  | "latest_broker_daily"
  | "gap_count"
  | "flow_balance"
  | "price_return"
  | "ohlcv_rows"

export type ReconciliationFilter =
  | "stale_broker"
  | "missing_ohlcv"
  | "with_gaps"
  | "accumulating"
  | "distributing"
  | "insufficient_daily"

/**
 * The flow keys are window-suffixed (`flow_balance_5d`) because the windows
 * are configuration, not a fixed pair. The declared fields are the stable
 * ones; the suffixed ones are read through `flowValue` below rather than
 * being hard-coded to a window this file cannot know.
 */
export type ReconciliationRow = {
  symbol: string
  hash: string | null
  size: number | null
  generated_at: string | null
  ohlcv_first: string | null
  ohlcv_last: string | null
  ohlcv_rows: number
  ohlcv_source_exists: boolean
  broker_first: string | null
  broker_last: string | null
  latest_broker_daily: string | null
  broker_daily_windows: number
  broker_aggregate_windows: number
  integrity_status: IntegrityStatus
  gap_count: number
  warning_count: number
  error_count: number
  broker_lag_sessions: number | null
  latest_accdist: string | null
  latest_accdist_score: number | null
  daily_sessions_total: number
} & Record<string, unknown>

export type ReconciliationList = {
  generated_at: string | null
  market_date: string | null
  schema_version: number | null
  summary: Record<string, number | string | null>
  flow_window: number
  total: number
  page: number
  per_page: number
  last_page: number
  rows: ReconciliationRow[]
}

export type OhlcvBar = {
  date: string
  open: number | null
  high: number | null
  low: number | null
  close: number | null
  volume: number | null
}

export type DailyFlowRow = {
  date: string
  transaction_type: string | null
  broker_accdist: string | null
  accdist_score: number | null
  number_broker_buysell: number | null
  turnover_value: number | null
  turnover_volume: number | null
  average_price: number | null
}

/**
 * A window is the unit a Stockbit response actually describes. A range
 * aggregate is shown as the range it covers and never as its component days.
 */
export type WindowRow = {
  from_date: string | null
  to_date: string | null
  is_single_day: boolean | null
  transaction_type: string | null
  source_filename: string | null
  source_hash: string | null
  entry_count: number
  broker_accdist: string | null
}

export type ReconciliationDetail = {
  symbol: string
  schema_version: number | null
  generated_at: string | null
  as_of_trading_date: string | null
  source_fingerprint: string | null
  asset: {
    symbol: string
    name: string | null
    sector: string | null
    sync_price: boolean
    sync_broker_summary: boolean
  }
  coverage: {
    ohlcv: {
      first_date: string | null
      last_date: string | null
      rows: number
      source_path: string
      source_exists: boolean
      source_hash: string | null
      source_size: number | null
    }
    broker_summary: {
      first_window_from: string | null
      last_window_to: string | null
      window_count: number
      single_day_window_count: number
      aggregate_window_count: number
      latest_single_day: string | null
      daily_flow_sessions: number
    }
  }
  integrity: {
    status: IntegrityStatus
    warnings: string[]
    errors: string[]
    missing_broker_sessions: string[]
    missing_broker_session_count: number
    duplicate_ohlcv_dates: string[]
    invalid_broker_ranges: string[]
    duplicate_broker_windows: string[]
    missing_source_files: string[]
    broker_lag_sessions: number | null
  }
  insight: Record<string, number | string | null>
  manifest_entry: ReconciliationRow | null
  document_hash: string | null
  document_size: number | null
  recent_ohlcv: OhlcvBar[]
  recent_daily_flow: DailyFlowRow[]
  recent_windows: WindowRow[]
}

export type ReconciliationQuery = {
  search?: string
  status?: IntegrityStatus
  filter?: ReconciliationFilter
  sort?: ReconciliationSort
  direction?: "asc" | "desc"
  page?: number
  per_page?: number
}

function message(payload: ApiResponse<unknown> | null, fallback: string): string {
  return (
    (payload && "message" in payload && typeof payload.message === "string" && payload.message) ||
    fallback
  )
}

async function get<T>(token: string, path: string, fallback: string): Promise<T> {
  const response = await fetch(buildApiUrl(path), {
    headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
  })
  const payload = await parseJson<ApiResponse<T>>(response)

  if (!response.ok || !payload || payload.status !== "success") {
    throw new Error(message(payload, fallback))
  }

  return payload.data
}

export function fetchReconciliation(
  token: string,
  query: ReconciliationQuery = {},
): Promise<ReconciliationList> {
  const params = new URLSearchParams()

  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== "") {
      params.set(key, String(value))
    }
  }

  const suffix = params.toString()

  return get<ReconciliationList>(
    token,
    `/v1/reconciliation${suffix ? `?${suffix}` : ""}`,
    "Unable to load the reconciliation index",
  )
}

/** The symbol is validated server-side; the client never sends a path. */
export function fetchReconciliationAsset(
  token: string,
  symbol: string,
): Promise<ReconciliationDetail> {
  return get<ReconciliationDetail>(
    token,
    `/v1/reconciliation/${encodeURIComponent(symbol.toUpperCase())}`,
    `Unable to load the reconciliation document for ${symbol}`,
  )
}

/** Read a window-suffixed metric without hard-coding the window. */
export function flowValue(
  row: Record<string, unknown>,
  prefix: string,
  window: number,
): number | null {
  const value = row[`${prefix}_${window}d`]

  return typeof value === "number" ? value : null
}
