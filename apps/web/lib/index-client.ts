import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

/**
 * Published index membership.
 *
 * The index is a list somebody else maintains; this dashboard follows a subset
 * of the market. So the useful question is not "what is in JII70" -- the
 * exchange publishes that -- but "which of it am I collecting, and what
 * changed since I last looked".
 */

export type IndexMember = {
  symbol: string
  /** The listed name, once an asset row exists to carry it. */
  name: string | null
  joined_on: string | null
  last_seen_on: string | null
  /** Null while the symbol is a current member. */
  removed_on: string | null
  tracked: boolean
  asset_id: number | null
  sync_price: boolean | null
  sync_broker_summary: boolean | null
  /** Null for an untracked symbol: nobody has tried to collect it. */
  bars: number | null
  last_bar_date: string | null
}

export type IndexSummary = {
  code: string
  name: string
  description: string | null
  source_url: string | null
  expected_size: number | null
  member_count: number
  tracked_count: number
  last_synced_at: string | null
  last_effective_on: string | null
  last_source: string | null
  recent_days?: number
}

export type IndexDetail = {
  index: IndexSummary
  members: IndexMember[]
  /** Members that left recently, so a badge that vanished can be explained. */
  departed: IndexMember[]
}

export type IndexSyncOutcome = {
  index: string
  source: string
  effective_on: string
  accepted: boolean
  refused_reason: string | null
  received: number
  previous_count: number
  member_count: number
  joined: string[]
  removed: string[]
  unchanged: number
  rejected: string[]
}

export type TrackOutcome = {
  created: string[]
  already_tracked: string[]
  /** Symbols that are not current members; the server refuses to invent them. */
  refused: string[]
}

function headers(accessToken: string, json = false): HeadersInit {
  return {
    Accept: "application/json",
    Authorization: `Bearer ${accessToken}`,
    ...(json ? { "Content-Type": "application/json" } : {}),
  }
}

async function request<T>(
  path: string,
  accessToken: string,
  init: RequestInit & { json?: unknown } = {},
  fallback = "Request failed.",
): Promise<T> {
  const { json, ...rest } = init

  const response = await fetch(buildApiUrl(path), {
    ...rest,
    headers: headers(accessToken, json !== undefined),
    ...(json !== undefined ? { body: JSON.stringify(json) } : {}),
  })

  const payload = await parseJson<ApiResponse<T>>(response)

  if (!response.ok || !payload || payload.status !== "success") {
    // A refused membership list comes back as 422 with the reason in the
    // message -- which is the part worth showing, so the message beats the
    // status here.
    const message =
      payload && "message" in payload && typeof payload.message === "string"
        ? payload.message
        : fallback

    throw new Error(message)
  }

  return payload.data as T
}

export function fetchIndexes(
  accessToken: string,
): Promise<{ default: string; indexes: IndexSummary[] }> {
  return request("/v1/indexes", accessToken, {}, "Unable to load the index list.")
}

export function fetchIndex(accessToken: string, code: string): Promise<IndexDetail> {
  return request(
    `/v1/indexes/${encodeURIComponent(code)}`,
    accessToken,
    {},
    "Unable to load that index.",
  )
}

export function replaceIndexMembers(
  accessToken: string,
  code: string,
  symbols: string[],
  force = false,
): Promise<IndexSyncOutcome> {
  return request(
    `/v1/indexes/${encodeURIComponent(code)}/members`,
    accessToken,
    { method: "POST", json: { symbols, force } },
    "Unable to update the membership.",
  )
}

export function trackIndexMembers(
  accessToken: string,
  code: string,
  symbols: string[],
): Promise<TrackOutcome> {
  return request(
    `/v1/indexes/${encodeURIComponent(code)}/track`,
    accessToken,
    { method: "POST", json: { symbols } },
    "Unable to add those symbols.",
  )
}

/**
 * Pull tickers out of whatever a person pasted.
 *
 * A copied index table arrives as symbols mixed with company names, prices and
 * percentages, in no fixed order. Splitting on whitespace and keeping the
 * ticker-shaped words is enough, and the server applies the real filter and
 * the safety guards afterwards -- this only exists so the textarea does not
 * demand a tidy comma-separated list nobody has.
 */
export function parsePastedSymbols(raw: string): string[] {
  const seen = new Set<string>()

  for (const word of raw.split(/[\s,;|]+/)) {
    const candidate = word.trim().toUpperCase()

    if (/^[A-Z][A-Z0-9]{2,5}$/.test(candidate)) seen.add(candidate)
  }

  return [...seen]
}
