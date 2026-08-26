import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

/**
 * A broker summary is a Stockbit aggregate over from_date..to_date, not a
 * trading day. A single-day summary is simply from_date === to_date, so there
 * is no separate daily shape.
 */
export type BrokerSide = "buy" | "sell"

export type BrokerEntry = {
  id: number
  broker_code: string
  side: BrokerSide
  broker_type: string | null
  frequency: number | null
  /** netbs_date — the range start repeated. Audit only; never a trading date. */
  source_date: string | null
  net_lot: number | null
  net_value: number | null
  gross_volume: number | null
  gross_value: number | null
  average_price: number | null
  window?: {
    id: number
    from_date: string | null
    to_date: string | null
    transaction_type: string | null
    is_single_day: boolean
    symbol: string | null
  }
}

export type Coverage = {
  returned_buyer_count: number
  total_buyer: number | null
  buyers_truncated: boolean
  returned_seller_count: number
  total_seller: number | null
  sellers_truncated: boolean
}

/** Groups are passed through whole, so metrics added later still arrive. */
export type DetectorMetricGroup = Record<string, unknown>

export type BandarDetector = {
  broker_accdist: string | null
  number_broker_buysell: number | null
  total_buyer: number | null
  total_seller: number | null
  value: number | null
  volume: number | null
  average_price: number | null
  metrics: Record<string, DetectorMetricGroup> | null
}

export type BrokerWindow = {
  id: number
  symbol?: string
  from_date: string | null
  to_date: string | null
  transaction_type: string | null
  is_single_day: boolean
  market_board: string | null
  investor_type: string | null
  imported_at: string | null
  coverage: Coverage
  buyers?: BrokerEntry[]
  sellers?: BrokerEntry[]
  bandar_detector?: BandarDetector | null
}

export type PageMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
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

export type WindowFilters = {
  symbol?: string
  windowFrom?: string
  windowTo?: string
  /**
   * `exact` selects the window with precisely these endpoints — the right
   * question when picking out one imported aggregate. `overlap` returns every
   * window intersecting the range, which is a different question.
   */
  match?: "exact" | "overlap"
  transactionType?: string
  perPage?: number
  page?: number
}

function rangeParams(url: URLSearchParams, filters: WindowFilters): void {
  if (filters.symbol) url.set("symbol", filters.symbol)
  if (filters.windowFrom) url.set("window_from", filters.windowFrom)
  if (filters.windowTo) url.set("window_to", filters.windowTo)
  if (filters.match) url.set("match", filters.match)
  if (filters.transactionType) url.set("transaction_type", filters.transactionType)
  if (filters.perPage) url.set("per_page", String(filters.perPage))
  if (filters.page) url.set("page", String(filters.page))
}

export async function fetchWindows(
  token: string,
  filters: WindowFilters,
): Promise<{ windows: BrokerWindow[]; meta: PageMeta }> {
  const params = new URLSearchParams()
  rangeParams(params, filters)

  return get(token, `/v1/broker-summary/windows?${params}`, "Unable to load broker summary windows")
}

export async function fetchWindow(token: string, id: number): Promise<{ window: BrokerWindow }> {
  return get(token, `/v1/broker-summary/windows/${id}`, "Unable to load that broker summary window")
}

export type EntryFilters = WindowFilters & {
  broker?: string
  brokerType?: string
  side?: BrokerSide
  sort?: string
  direction?: "asc" | "desc"
}

export async function fetchEntries(
  token: string,
  filters: EntryFilters,
): Promise<{ entries: BrokerEntry[]; meta: PageMeta }> {
  const params = new URLSearchParams()
  rangeParams(params, filters)
  if (filters.broker) params.set("broker", filters.broker)
  if (filters.brokerType) params.set("broker_type", filters.brokerType)
  if (filters.side) params.set("side", filters.side)
  if (filters.sort) params.set("sort", filters.sort)
  if (filters.direction) params.set("direction", filters.direction)

  return get(token, `/v1/broker-summary/entries?${params}`, "Unable to load broker entries")
}

const DATE = new Intl.DateTimeFormat(undefined, {
  day: "numeric",
  month: "short",
  year: "numeric",
})

export function formatDate(value: string | null): string {
  return value ? DATE.format(new Date(`${value}T00:00:00`)) : "—"
}

/** The window's identity, shown as a range so it cannot read as a day. */
export function formatWindow(from: string | null, to: string | null): string {
  if (!from && !to) return "—"
  if (from === to) return formatDate(from)

  return `${formatDate(from)} → ${formatDate(to)}`
}

export function formatNumber(value: number | null): string {
  return value === null || Number.isNaN(value)
    ? "—"
    : new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(value)
}

export function formatCompact(value: number | null): string {
  return value === null || Number.isNaN(value)
    ? "—"
    : new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 2 }).format(
        value,
      )
}

export function formatPrice(value: number | null): string {
  return value === null || Number.isNaN(value)
    ? "—"
    : new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value)
}
