import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

export type PortfolioRecord = {
  id: number
  name: string
  base_ccy: string
  positions_count?: number
  positions?: PositionRecord[]
}

export type PositionRecord = {
  id: number
  portfolio_id: number
  asset_id: number
  asset?: {
    id: number
    symbol: string
    name: string
  }
  side: "long" | "short"
  qty_shares: number
  avg_price: number
  entry_date: string | null
  trail: number | null
  status: "open" | "closed"
  notional_value?: number
}

export type PortfolioPayload = {
  name: string
  baseCcy: string
}

export type PositionPayload = {
  assetId: number
  side: "long" | "short"
  qtyShares: number
  avgPrice: number
  entryDate: string
  trail?: number | null
  status?: "open" | "closed"
}

type FetchOptions = {
  includePositions?: boolean
}

function buildHeaders(accessToken: string): HeadersInit {
  return {
    Accept: "application/json",
    Authorization: `Bearer ${accessToken}`,
    "Content-Type": "application/json",
  }
}

function extractErrorMessage(payload: ApiResponse<unknown> | null, fallback: string): string {
  if (payload && payload.status === "error" && payload.message) {
    return typeof payload.message === "string" ? payload.message : fallback
  }

  return fallback
}

export async function fetchPortfolios(
  accessToken: string,
  options: FetchOptions = {},
): Promise<PortfolioRecord[]> {
  const include = options.includePositions ? "?include=positions.asset" : ""

  const response = await fetch(buildApiUrl(`/v1/portfolios${include}`), {
    method: "GET",
    headers: buildHeaders(accessToken),
  })

  const payload = await parseJson<ApiResponse<PortfolioRecord[]>>(response)

  if (!response.ok || !payload || payload.status !== "success" || !Array.isArray(payload.data)) {
    throw new Error(extractErrorMessage(payload, "Unable to load portfolios."))
  }

  return payload.data
}

export async function createPortfolio(accessToken: string, payload: PortfolioPayload): Promise<PortfolioRecord> {
  const response = await fetch(buildApiUrl("/v1/portfolios"), {
    method: "POST",
    headers: buildHeaders(accessToken),
    body: JSON.stringify({
      name: payload.name,
      base_ccy: payload.baseCcy,
    }),
  })

  const data = await parseJson<ApiResponse<PortfolioRecord>>(response)

  if (!response.ok || !data || data.status !== "success" || !data.data) {
    throw new Error(extractErrorMessage(data, "Unable to create portfolio."))
  }

  return data.data
}

export async function updatePortfolio(
  accessToken: string,
  portfolioId: number,
  payload: Partial<PortfolioPayload>,
): Promise<PortfolioRecord> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}`), {
    method: "PUT",
    headers: buildHeaders(accessToken),
    body: JSON.stringify({
      ...(payload.name ? { name: payload.name } : {}),
      ...(payload.baseCcy ? { base_ccy: payload.baseCcy } : {}),
    }),
  })

  const data = await parseJson<ApiResponse<PortfolioRecord>>(response)

  if (!response.ok || !data || data.status !== "success" || !data.data) {
    throw new Error(extractErrorMessage(data, "Unable to update portfolio."))
  }

  return data.data
}

export async function deletePortfolio(accessToken: string, portfolioId: number): Promise<void> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}`), {
    method: "DELETE",
    headers: buildHeaders(accessToken),
  })

  const data = await parseJson<ApiResponse<null>>(response)

  if (!response.ok || !data || data.status !== "success") {
    throw new Error(extractErrorMessage(data, "Unable to delete portfolio."))
  }
}

function buildPositionPayload(payload: PositionPayload): Record<string, string | number | null> {
  return {
    asset_id: payload.assetId,
    side: payload.side,
    qty_shares: payload.qtyShares,
    avg_price: payload.avgPrice,
    entry_date: payload.entryDate,
    trail: payload.trail ?? null,
    ...(payload.status ? { status: payload.status } : {}),
  }
}

export async function createPosition(
  accessToken: string,
  portfolioId: number,
  payload: PositionPayload,
): Promise<PositionRecord> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}/positions`), {
    method: "POST",
    headers: buildHeaders(accessToken),
    body: JSON.stringify(buildPositionPayload(payload)),
  })

  const data = await parseJson<ApiResponse<PositionRecord>>(response)

  if (!response.ok || !data || data.status !== "success" || !data.data) {
    throw new Error(extractErrorMessage(data, "Unable to create position."))
  }

  return data.data
}

export async function updatePosition(
  accessToken: string,
  portfolioId: number,
  positionId: number,
  payload: PositionPayload,
): Promise<PositionRecord> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}/positions/${positionId}`), {
    method: "PUT",
    headers: buildHeaders(accessToken),
    body: JSON.stringify(buildPositionPayload(payload)),
  })

  const data = await parseJson<ApiResponse<PositionRecord>>(response)

  if (!response.ok || !data || data.status !== "success" || !data.data) {
    throw new Error(extractErrorMessage(data, "Unable to update position."))
  }

  return data.data
}

export async function deletePosition(
  accessToken: string,
  portfolioId: number,
  positionId: number,
): Promise<void> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}/positions/${positionId}`), {
    method: "DELETE",
    headers: buildHeaders(accessToken),
  })

  const data = await parseJson<ApiResponse<null>>(response)

  if (!response.ok || !data || data.status !== "success") {
    throw new Error(extractErrorMessage(data, "Unable to delete position."))
  }
}
