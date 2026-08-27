import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

export type WatchlistTopBroker = {
  broker: string
  net_value: number
  /**
   * The range the figure covers. A broker summary is an aggregate over
   * from..to, so a net value can be one session or three months of flow, and
   * the two must not read the same.
   */
  from?: string | null
  to?: string | null
}

export type WatchlistRow = {
  id: number
  scan_date: string | null
  symbol: string
  asset: {
    id: number
    symbol: string
    name: string | null
    sector: string | null
  } | null
  version: string
  close: number | null
  net_value: number | null
  vol_ratio_20: number | null
  breakout20: boolean
  score_total: number
  score_bas: number
  score_bcs: number
  lf_pass: boolean
  rrf_pass: boolean
  invalidation_level: number | null
  take_profit: number | null
  risk_reward: number | null
  top_brokers: WatchlistTopBroker[]
  reasons: string[]
  risk_notes: string | null
}

export type WatchlistPayload = {
  scan_date: string | null
  version: string
  rows: WatchlistRow[]
  disclaimer?: string
}

export type WatchlistFilters = {
  date?: string
  version?: string
  symbol?: string
  minScore?: number
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

export async function fetchWatchlist(
  accessToken: string,
  filters: WatchlistFilters = {},
): Promise<WatchlistPayload> {
  const params = new URLSearchParams()
  if (filters.date) params.set("date", filters.date)
  if (filters.version) params.set("version", filters.version)
  if (filters.symbol) params.set("symbol", filters.symbol.toUpperCase())
  if (filters.minScore !== undefined) params.set("min_score", String(filters.minScore))
  if (filters.limit !== undefined) params.set("limit", String(filters.limit))

  const query = params.toString()
  const url = buildApiUrl(`/v1/strategy/watchlist${query ? `?${query}` : ""}`)

  const response = await fetch(url, {
    method: "GET",
    headers: buildHeaders(accessToken),
  })

  const payload = await parseJson<ApiResponse<WatchlistPayload>>(response)

  if (!response.ok || !payload || payload.status !== "success" || !payload.data) {
    throw new Error(extractErrorMessage(payload, "Unable to load watchlist."))
  }

  return payload.data
}
