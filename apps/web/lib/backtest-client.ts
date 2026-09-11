import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

/**
 * Backtests that outlive the request that asked for them.
 *
 * The endpoints are synchronous: a symbol over a couple of years runs in tens
 * of milliseconds, and the point of running one from the dashboard is to
 * change a parameter and look again. Nothing here polls.
 */

export type BacktestStats = {
  cagr?: number | null
  maxdd?: number | null
  sharpe?: number | null
  winrate?: number | null
  profit_factor?: number | null
  trades?: number | null
  final_equity?: number | null
  return_pct?: number | null
  /**
   * Keys whose true value is infinite rather than unknown -- a profit factor
   * with no losing trades to divide by. Null plus this list is how the API
   * distinguishes "could not measure" from "there were no losses".
   */
  unbounded?: string[] | null
}

export type BacktestParams = {
  capital?: number | null
  from?: string | null
  to?: string | null
  bars?: number | null
  trailing?: { type?: string; percent?: number; multiple?: number; period?: number } | null
}

export type BacktestTrade = {
  entry_date: string | null
  exit_date: string | null
  entry_px: number
  exit_px: number | null
  units: number
  pnl: number | null
}

export type BacktestRun = {
  run_id: string
  symbol: string | null
  strategy: string | null
  source: string | null
  created_at: string | null
  params: BacktestParams | null
  stats: BacktestStats | null
  notes: string | null
  trades?: BacktestTrade[]
}

export type StrategyComparisonRow = {
  strategy: string
  label: string
  /** Null when this strategy has never been run for the symbol. */
  run: BacktestRun | null
}

/**
 * Just enough of a built-in strategy to offer it in a select.
 *
 * Deliberately structural rather than importing the strategy-builder type: the
 * panel needs a key and a label, and coupling it to the fuller shape would
 * make every field added there a change here.
 */
export type BuiltInStrategyOption = {
  key: string
  label?: string | null
}

export type BacktestRequest = {
  symbol: string
  strategy?: string
  compare?: boolean
  capital?: number
  from?: string | null
  to?: string | null
  trailing?: { type: "percent" | "atr"; percent?: number; multiple?: number; period?: number } | null
  notes?: string | null
}

function headers(accessToken: string, json = false): HeadersInit {
  return {
    Accept: "application/json",
    Authorization: `Bearer ${accessToken}`,
    ...(json ? { "Content-Type": "application/json" } : {}),
  }
}

async function request<T>(
  url: string,
  accessToken: string,
  init: RequestInit & { json?: unknown } = {},
  fallback = "Request failed.",
): Promise<T> {
  const { json, ...rest } = init

  const response = await fetch(buildApiUrl(url), {
    ...rest,
    headers: headers(accessToken, json !== undefined),
    ...(json !== undefined ? { body: JSON.stringify(json) } : {}),
  })

  const payload = await parseJson<ApiResponse<T>>(response)

  if (!response.ok || !payload || payload.status !== "success") {
    // The API answers a bad symbol, an unknown strategy and a range with too
    // little history all as 422 with the reason in the message, so the message
    // is worth more than the status here.
    const message =
      payload && "message" in payload && typeof payload.message === "string"
        ? payload.message
        : fallback

    throw new Error(message)
  }

  return payload.data as T
}

export function runBacktest(
  accessToken: string,
  body: BacktestRequest,
): Promise<{ run?: BacktestRun; runs?: BacktestRun[] }> {
  return request<{ run?: BacktestRun; runs?: BacktestRun[] }>(
    "/v1/backtests",
    accessToken,
    { method: "POST", json: body },
    "Unable to run the backtest.",
  )
}

export async function fetchBacktests(
  accessToken: string,
  filters: { symbol?: string; strategy?: string; limit?: number } = {},
): Promise<BacktestRun[]> {
  const query = new URLSearchParams()

  if (filters.symbol) query.set("symbol", filters.symbol)
  if (filters.strategy) query.set("strategy", filters.strategy)
  if (filters.limit) query.set("limit", String(filters.limit))

  const suffix = query.toString() ? `?${query.toString()}` : ""

  const { runs } = await request<{ runs: BacktestRun[] }>(
    `/v1/backtests${suffix}`,
    accessToken,
    {},
    "Unable to load backtest history.",
  )

  return runs
}

export function fetchBacktest(accessToken: string, runId: string): Promise<{ run: BacktestRun }> {
  return request<{ run: BacktestRun }>(
    `/v1/backtests/${runId}`,
    accessToken,
    {},
    "Unable to load that backtest.",
  )
}

export function fetchStrategyComparison(
  accessToken: string,
  symbol: string,
): Promise<{ symbol: string; strategies: StrategyComparisonRow[] }> {
  return request<{ symbol: string; strategies: StrategyComparisonRow[] }>(
    `/v1/backtests/comparison?symbol=${encodeURIComponent(symbol)}`,
    accessToken,
    {},
    "Unable to load the comparison.",
  )
}

const percent = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1,
})

const ratio = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

/**
 * A statistic the run could not bound is shown as such.
 *
 * Rendering a null profit factor as 0 would say every trade lost, which is the
 * opposite of what an unbounded one means, so the two cases are separated
 * here rather than collapsed into a dash.
 */
export function formatStat(
  stats: BacktestStats | null | undefined,
  key: keyof BacktestStats,
  kind: "percent" | "ratio" | "count" = "ratio",
): string {
  if (!stats) return "—"

  const value = stats[key]

  if (value === null || value === undefined) {
    return stats.unbounded?.includes(key) ? "∞" : "—"
  }

  if (typeof value !== "number" || !Number.isFinite(value)) return "—"

  if (kind === "count") return String(Math.round(value))
  if (kind === "percent") return `${percent.format(value * 100)}%`

  return ratio.format(value)
}
