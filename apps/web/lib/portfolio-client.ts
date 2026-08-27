import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

export type PortfolioRecord = {
  id: number
  user_id?: number | null
  name: string
  base_ccy: string
  cash_balance?: number
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
  /** Full execution timestamp; several fills can share a date. */
  executed_at_iso?: string | null
  /** "stockbit" / "stockbit_snapshot" for imported rows, null when manual. */
  source?: string | null
  external_id?: string | null
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
    latest_close: number | null
    latest_close_date: string | null
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

export type Holding = {
  asset_id: number
  symbol: string | null
  name: string | null
  sector: string | null
  qty: number
  avg_cost: number
  cost_basis: number
  latest_close: number | null
  latest_close_date: string | null
  market_value: number
  unrealized_pl: number
  unrealized_pl_pct: number | null
  last_executed_at: string | null
}

export type AllocationRow = {
  asset_id?: number
  symbol?: string | null
  sector?: string | null
  value: number
  pct: number | null
}

export type PortfolioSummaryPayload = {
  portfolio_id: number
  base_ccy: string
  cash_balance: number
  total_market_value: number
  total_equity: number
  realized_pl: number
  unrealized_pl: number
  holdings: Holding[]
  allocation_by_symbol: AllocationRow[]
  allocation_by_sector: AllocationRow[]
}

export type CashMovementKind = "deposit" | "withdraw" | "fee" | "dividend" | "adjustment"

export type CashMovementRecord = {
  id: number
  portfolio_id: number
  kind: CashMovementKind
  amount: number
  signed_amount: number
  executed_at: string | null
  executed_at_iso?: string | null
  note: string | null
  source?: string | null
  external_id?: string | null
}

export type CashMovementPayload = {
  kind: CashMovementKind
  amount: number
  executedAt: string
  note?: string
}

export async function fetchPortfolioSummary(
  accessToken: string,
  portfolioId: number,
): Promise<PortfolioSummaryPayload> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}/summary`), {
    method: "GET",
    headers: buildHeaders(accessToken),
  })

  const payload = await parseJson<ApiResponse<PortfolioSummaryPayload>>(response)

  if (!response.ok || !payload || payload.status !== "success" || !payload.data) {
    throw new Error(extractErrorMessage(payload, "Unable to load portfolio summary."))
  }

  return payload.data
}

export async function fetchCashMovements(
  accessToken: string,
  portfolioId: number,
): Promise<CashMovementRecord[]> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}/cash-movements?limit=200`), {
    method: "GET",
    headers: buildHeaders(accessToken),
  })

  const payload = await parseJson<
    ApiResponse<{ portfolio_id: number; rows: CashMovementRecord[] }>
  >(response)

  if (!response.ok || !payload || payload.status !== "success" || !payload.data) {
    throw new Error(extractErrorMessage(payload, "Unable to load cash movements."))
  }

  return payload.data.rows ?? []
}

export async function createCashMovement(
  accessToken: string,
  portfolioId: number,
  payload: CashMovementPayload,
): Promise<CashMovementRecord> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}/cash-movements`), {
    method: "POST",
    headers: buildHeaders(accessToken),
    body: JSON.stringify({
      kind: payload.kind,
      amount: payload.amount,
      executed_at: payload.executedAt,
      note: payload.note ?? null,
    }),
  })

  const data = await parseJson<ApiResponse<CashMovementRecord>>(response)

  if (!response.ok || !data || data.status !== "success" || !data.data) {
    throw new Error(extractErrorMessage(data, "Unable to record cash movement."))
  }

  return data.data
}

export async function deleteCashMovement(
  accessToken: string,
  portfolioId: number,
  movementId: number,
): Promise<void> {
  const response = await fetch(
    buildApiUrl(`/v1/portfolios/${portfolioId}/cash-movements/${movementId}`),
    {
      method: "DELETE",
      headers: buildHeaders(accessToken),
    },
  )

  const data = await parseJson<ApiResponse<null>>(response)

  if (!response.ok || !data || data.status !== "success") {
    throw new Error(extractErrorMessage(data, "Unable to delete cash movement."))
  }
}

/* ------------------------------------------------------------------------ *
 * Stockbit JSON import
 *
 * Preview writes nothing; the commit re-runs the same analysis server-side, so
 * nothing here is trusted as import input — these types only describe what the
 * server decided so the dialog can render it.
 * ------------------------------------------------------------------------ */

export type ImportPayloadType = "history" | "snapshot"

export type ImportRowStatus = "new" | "skipped_duplicate" | "skipped" | "error"

export type ImportRow = {
  external_id: string | null
  command: string
  symbol: string
  status: string
  executed_at: string | null
  shares: number | null
  price: number | null
  fee: number | null
  amount: number | null
  net_amount: number | null
  import_status: ImportRowStatus
  reason: string | null
  asset_id?: number | null
  side?: "entry" | "exit"
  fee_rate?: number
  effective_unit_price?: number
  net_amount_calculated?: number
  kind?: string
  note?: string
}

export type SnapshotReconciliationRow = {
  symbol: string
  asset_id: number | null
  broker_shares: number
  breakout_shares: number
  shares_match: boolean
  broker_average_price: number | null
  breakout_average_cost: number | null
  average_match: boolean | null
  broker_amount_invested: number | null
  breakout_cost_basis: number | null
  invested_match: boolean | null
  broker_market_value: number | null
  breakout_market_value: number | null
  market_value_match: boolean | null
  broker_unrealized_pl: number | null
  broker_latest_price: number | null
  import_status: ImportRowStatus
  reason: string | null
  opening_position_eligible?: boolean
}

export type SnapshotCashReconciliation = {
  broker_cash: number
  current_base_cash: number
  cash_movements_total: number
  current_calculated_cash: number
  proposed_base_cash: number
  adjustment: number
  already_reconciled: boolean
  can_reconcile: boolean
}

export type ImportTotals = {
  new: number
  skipped_duplicate: number
  skipped: number
  error: number
  rows: number
}

export type ImportAnalysis = {
  type: ImportPayloadType
  portfolio_id: number
  trades: ImportRow[]
  dividends: ImportRow[]
  skipped: ImportRow[]
  errors: ImportRow[]
  warnings: string[]
  missing_assets: string[]
  snapshot: {
    positions: SnapshotReconciliationRow[]
    cash: SnapshotCashReconciliation | null
    broker_summary: Record<string, number | null>
  } | null
  totals: ImportTotals
  can_commit: boolean
}

export type ImportResult = ImportAnalysis & {
  committed: boolean
  created: { positions: number; cash_movements: number }
  created_position_ids?: number[]
  created_cash_movement_ids?: number[]
  cash_balance_set_to?: number | null
}

export type ImportOptions = {
  createSnapshotPositions?: boolean
  reconcileCash?: boolean
}

function importBody(payload: string, options: ImportOptions) {
  return JSON.stringify({
    payload,
    create_snapshot_positions: options.createSnapshotPositions ?? false,
    reconcile_cash: options.reconcileCash ?? false,
  })
}

export async function previewStockbitImport(
  accessToken: string,
  portfolioId: number,
  payload: string,
  options: ImportOptions = {},
): Promise<ImportAnalysis> {
  const response = await fetch(
    buildApiUrl(`/v1/portfolios/${portfolioId}/imports/stockbit/preview`),
    { method: "POST", headers: buildHeaders(accessToken), body: importBody(payload, options) },
  )

  const data = await parseJson<ApiResponse<ImportAnalysis>>(response)

  if (!response.ok || !data || data.status !== "success" || !data.data) {
    throw new Error(extractErrorMessage(data, "Unable to preview this import."))
  }

  return data.data
}

export async function commitStockbitImport(
  accessToken: string,
  portfolioId: number,
  payload: string,
  options: ImportOptions = {},
): Promise<{ result: ImportResult; message: string }> {
  const response = await fetch(buildApiUrl(`/v1/portfolios/${portfolioId}/imports/stockbit`), {
    method: "POST",
    headers: buildHeaders(accessToken),
    body: importBody(payload, options),
  })

  const data = await parseJson<ApiResponse<ImportResult>>(response)

  if (!response.ok || !data || data.status !== "success" || !data.data) {
    throw new Error(extractErrorMessage(data, "Unable to import this payload."))
  }

  return {
    result: data.data,
    message: typeof data.message === "string" ? data.message : "Import complete.",
  }
}

export const IMPORT_STATUS_LABELS: Record<ImportRowStatus, string> = {
  new: "NEW",
  skipped_duplicate: "DUPLICATE",
  skipped: "SKIPPED",
  error: "ERROR",
}

export const IMPORT_STATUS_TONE: Record<ImportRowStatus, string> = {
  new: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400",
  skipped_duplicate: "bg-sky-500/10 text-sky-700 dark:text-sky-400",
  skipped: "bg-amber-500/10 text-amber-700 dark:text-amber-400",
  error: "bg-destructive/10 text-destructive",
}
