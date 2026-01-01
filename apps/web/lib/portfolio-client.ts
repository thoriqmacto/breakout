import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

export type PortfolioRecord = {
  id: number
  name: string
  base_ccy: string
  remarks: string | null
  year: number | null
  positions_count?: number
  positions?: PositionRecord[]
  asset_summaries?: AssetSummary[]
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
  side: "entry" | "exit"
  qty_shares: number
  price: number
  fee_rate: number
  fee_value: number
  avg_price: number
  executed_at: string | null
  value?: number
}

export type AssetSummary = {
  asset: {
    id: number
    symbol: string
    name: string
  } | null
  entry: {
    min_price: number | null
    max_price: number | null
    total_shares: number
    average_price: number | null
    value: number
  }
  exit: {
    min_price: number | null
    max_price: number | null
    total_shares: number
    average_price: number | null
    value: number
    pl_percent: number | null
    pl_value: number
    pl_flag: "profit" | "loss" | "breakeven"
  }
}

export type PortfolioPayload = {
  name: string
  baseCcy: string
  remarks?: string
  year?: number
}

export type PositionPayload = {
  assetId: number
  side: "entry" | "exit"
  qtyShares: number
  price: number
  feeRate?: number
  executedAt: string
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
      remarks: payload.remarks ?? null,
      year: payload.year ?? null,
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
      ...(payload.remarks !== undefined ? { remarks: payload.remarks } : {}),
      ...(payload.year ? { year: payload.year } : {}),
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
    price: payload.price,
    fee_rate: payload.feeRate ?? 0,
    executed_at: payload.executedAt,
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
