"use client"

import { useEffect, useMemo, useState } from "react"

import { useAuth } from "@/components/auth-provider"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

const integerFormatter = new Intl.NumberFormat("en-US", {
  maximumFractionDigits: 0,
})

const priceFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
})

const ratioFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

type AssetMetricApiRow = {
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
}

type AssetMetricRow = {
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
}

type AssetMetricsResponse = {
  metrics?: AssetMetricApiRow[]
}

const parseNumericValue = (value: unknown): number | null => {
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

const parseBooleanValue = (value: unknown): boolean | null => {
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

const parseIntegerValue = (value: unknown): number => {
  const parsed = Number.parseInt(String(value ?? ""), 10)
  return Number.isNaN(parsed) ? 0 : parsed
}

const normalizeMetrics = (rows: AssetMetricApiRow[]): AssetMetricRow[] =>
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
  }))

const formatNumber = (
  value: number | null,
  formatter: Intl.NumberFormat,
  options: { suffix?: string } = {},
) => {
  if (value === null || Number.isNaN(value)) {
    return "—"
  }

  return `${formatter.format(value)}${options.suffix ?? ""}`
}

const formatRatio = (value: number | null) => {
  if (value === null || Number.isNaN(value)) {
    return "—"
  }

  return ratioFormatter.format(value)
}

export default function AssetsMetricsPage() {
  const { accessToken } = useAuth()
  const [rows, setRows] = useState<AssetMetricRow[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!accessToken) {
      return
    }

    const controller = new AbortController()
    setLoading(true)
    setError(null)

    fetch(buildApiUrl("/v1/assets/metrics"), {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    })
      .then(async (response) => {
        const payload = await parseJson<ApiResponse<AssetMetricsResponse>>(response)

        if (!response.ok) {
          const message =
            (payload && "message" in payload && payload.message) ||
            "Unable to fetch asset metrics."
          throw new Error(typeof message === "string" ? message : "Unable to fetch asset metrics.")
        }

        if (!payload || payload.status !== "success") {
          const message =
            payload && "message" in payload && payload.message
              ? payload.message
              : "Unexpected response from the asset metrics API."
          throw new Error(typeof message === "string" ? message : "Unexpected response from the asset metrics API.")
        }

        const metrics = payload.data?.metrics ?? []

        if (!Array.isArray(metrics)) {
          throw new Error("Unexpected response format from the asset metrics API.")
        }

        setRows(normalizeMetrics(metrics))
      })
      .catch((cause) => {
        if ((cause as Error)?.name === "AbortError") {
          return
        }

        const message =
          cause instanceof Error && cause.message
            ? cause.message
            : "Something went wrong while loading asset metrics."
        setError(message)
        setRows([])
      })
      .finally(() => setLoading(false))

    return () => {
      controller.abort()
    }
  }, [accessToken])

  const content = useMemo(() => {
    if (loading) {
      return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-lg border bg-background/70 px-6 py-12 text-center text-sm text-muted-foreground">
          <div className="size-8 animate-spin rounded-full border-2 border-muted border-t-primary" aria-hidden />
          Loading asset metrics…
        </div>
      )
    }

    if (error) {
      return (
        <div className="rounded-lg border border-destructive/40 bg-destructive/5 px-6 py-4 text-sm text-destructive">
          {error}
        </div>
      )
    }

    if (rows.length === 0) {
      return (
        <div className="rounded-lg border bg-background/70 px-6 py-8 text-sm text-muted-foreground">
          No asset metrics are available right now. Try again after new price data has been ingested.
        </div>
      )
    }

    return (
      <div className="overflow-x-auto">
        <table className="min-w-full border-separate border-spacing-0 overflow-hidden rounded-lg border bg-background text-sm shadow-sm">
          <thead className="bg-muted/60 text-xs uppercase tracking-wide text-muted-foreground">
            <tr>
              <th scope="col" className="sticky left-0 z-10 bg-muted/60 px-4 py-3 text-left font-semibold">
                Rank
              </th>
              <th scope="col" className="px-4 py-3 text-left font-semibold">
                Symbol
              </th>
              <th scope="col" className="px-4 py-3 text-left font-semibold">
                Name
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                Close
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                MA 50
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                MA 100
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                20w High
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                55w High
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                ATR 14d
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                ROC 13w (%)
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                Avg Vol 20d
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                Vol / Avg 20d
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                Close / 20wH
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                Close / 55wH
              </th>
              <th scope="col" className="px-4 py-3 text-left font-semibold">
                Uptrend
              </th>
              <th scope="col" className="px-4 py-3 text-right font-semibold">
                Bars
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={`${row.symbol}-${row.rank}`} className="border-t border-border/60">
                <td className="sticky left-0 z-10 bg-background px-4 py-3 font-medium">{row.rank}</td>
                <td className="px-4 py-3 font-medium text-foreground">{row.symbol}</td>
                <td className="px-4 py-3 text-muted-foreground">{row.name}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.close, priceFormatter)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.ma50, priceFormatter)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.ma100, priceFormatter)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.high20, priceFormatter)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.high55, priceFormatter)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.atr14, priceFormatter)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.roc13, ratioFormatter, { suffix: "%" })}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.avgVol20, integerFormatter)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatRatio(row.volVsAvg20)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatRatio(row.closeVsHigh20)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatRatio(row.closeVsHigh55)}</td>
                <td className="px-4 py-3">
                  {row.uptrend === null ? "—" : row.uptrend ? (
                    <span className="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-600">
                      Yes
                    </span>
                  ) : (
                    <span className="rounded-full bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">
                      No
                    </span>
                  )}
                </td>
                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(row.bars, integerFormatter)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )
  }, [error, loading, rows])

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">Asset Metrics</h1>
        <p className="text-sm text-muted-foreground">
          Review ticker rankings sourced from the <code className="rounded bg-muted px-1 py-0.5">asset:metrics --all</code> command without
          leaving the dashboard.
        </p>
      </div>
      {content}
    </div>
  )
}
