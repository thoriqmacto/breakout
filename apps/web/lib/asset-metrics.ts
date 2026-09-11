export type AssetMetricApiRow = {
  rank: number | string
  asset_id: number | string
  symbol: string
  name: string
  close: number | string | null
  ma50: number | string | null
  ma100: number | string | null
  high20: number | string | null
  high55: number | string | null
  atr14: number | string | null
  roc13: number | string | null
  avg_vol20: number | string | null
  vol_vs_avg20: number | string | null
  close_vs_high20: number | string | null
  close_vs_high55: number | string | null
  uptrend: boolean | string | number | null
  bars: number | string | null
  pbas: number | string | null
  bavg: number | string | null
  coverage?: AssetCoverageApi | null
}

/**
 * How complete the asset's history is, measured against the trading calendar.
 *
 * Absent (rather than zeroed) when the asset has no bars at all: "nothing
 * collected yet" and "collected with holes" are different states and the table
 * shows them differently.
 */
export type AssetCoverageApi = {
  bars: number | string | null
  first_bar_date: string | null
  last_bar_date: string | null
  sessions_expected: number | string | null
  sessions_missing: number | string | null
  sessions_behind: number | string | null
  complete: boolean | string | number | null
}

export type AssetCoverage = {
  bars: number
  firstBarDate: string | null
  lastBarDate: string | null
  sessionsExpected: number
  /** Sessions the market held inside this asset's own span that it has no bar for. */
  sessionsMissing: number
  /** Sessions held since its last bar -- staleness, which is a different fault. */
  sessionsBehind: number
  complete: boolean
}

export type AssetMetricRow = {
  rank: number
  assetId: number
  symbol: string
  name: string
  close: number | null
  ma50: number | null
  ma100: number | null
  high20: number | null
  high55: number | null
  atr14: number | null
  roc13: number | null
  avgVol20: number | null
  volVsAvg20: number | null
  closeVsHigh20: number | null
  closeVsHigh55: number | null
  uptrend: boolean | null
  bars: number | null
  pbas: number | null
  bavg: number | null
  coverage: AssetCoverage | null
}

const normalizeCoverage = (row: AssetCoverageApi | null | undefined): AssetCoverage | null => {
  if (!row) return null

  return {
    bars: parseIntegerValue(row.bars),
    firstBarDate: row.first_bar_date,
    lastBarDate: row.last_bar_date,
    sessionsExpected: parseIntegerValue(row.sessions_expected),
    sessionsMissing: parseIntegerValue(row.sessions_missing),
    sessionsBehind: parseIntegerValue(row.sessions_behind),
    complete: parseBooleanValue(row.complete) ?? false,
  }
}

export const parseNumericValue = (value: unknown): number | null => {
  if (typeof value === "number") {
    return Number.isFinite(value) ? value : null
  }

  if (typeof value === "string") {
    const trimmed = value.trim()
    if (!trimmed) {
      return null
    }

    const normalized = trimmed.replace(/,/g, "")
    const parsed = Number.parseFloat(normalized)
    return Number.isNaN(parsed) ? null : parsed
  }

  return null
}

export const parseBooleanValue = (value: unknown): boolean | null => {
  if (typeof value === "boolean") {
    return value
  }

  if (typeof value === "number") {
    return value > 0
  }

  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase()
    if (normalized === "true" || normalized === "yes" || normalized === "1") {
      return true
    }
    if (normalized === "false" || normalized === "no" || normalized === "0") {
      return false
    }
  }

  return null
}

export const parseIntegerValue = (value: unknown): number => {
  const parsed = Number.parseInt(String(value ?? ""), 10)
  return Number.isNaN(parsed) ? 0 : parsed
}

export const normalizeMetrics = (rows: AssetMetricApiRow[]): AssetMetricRow[] =>
  rows.map((row) => ({
    rank: parseIntegerValue(row.rank),
    assetId: parseIntegerValue(row.asset_id),
    symbol: row.symbol,
    name: row.name,
    close: parseNumericValue(row.close),
    ma50: parseNumericValue(row.ma50),
    ma100: parseNumericValue(row.ma100),
    high20: parseNumericValue(row.high20),
    high55: parseNumericValue(row.high55),
    atr14: parseNumericValue(row.atr14),
    roc13: parseNumericValue(row.roc13),
    avgVol20: parseNumericValue(row.avg_vol20),
    volVsAvg20: parseNumericValue(row.vol_vs_avg20),
    closeVsHigh20: parseNumericValue(row.close_vs_high20),
    closeVsHigh55: parseNumericValue(row.close_vs_high55),
    uptrend: parseBooleanValue(row.uptrend),
    bars: parseNumericValue(row.bars),
    pbas: parseNumericValue(row.pbas),
    bavg: parseNumericValue(row.bavg),
    coverage: normalizeCoverage(row.coverage),
  }))
