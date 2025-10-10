"use client"

import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react"

import { useAuth } from "@/components/auth-provider"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import { Button } from "@/components/ui/button"
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react"

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

type ColumnKey = keyof AssetMetricRow

type SortDirection = "asc" | "desc"

type SortRule = {
  key: ColumnKey
  direction: SortDirection
}

type ColumnDefinition = {
  key: ColumnKey
  label: string
  align: "left" | "right"
  headerStickyClassName?: string
  cellStickyClassName?: string
  headerClassName?: string
  cellClassName?: string
  getSortValue: (row: AssetMetricRow) => string | number | boolean | null
  getCopyValue: (row: AssetMetricRow) => string
  render: (row: AssetMetricRow) => ReactNode
}

const classNames = (
  ...classes: Array<string | false | null | undefined>
): string => classes.filter(Boolean).join(" ")

const ASSET_METRIC_COLUMNS: ColumnDefinition[] = [
  {
    key: "rank",
    label: "Rank",
    align: "left",
    headerStickyClassName: "sticky left-0 z-10 bg-muted/60",
    cellStickyClassName: "sticky left-0 z-10 bg-background",
    cellClassName: "font-medium",
    getSortValue: (row) => row.rank,
    getCopyValue: (row) => String(row.rank),
    render: (row) => row.rank,
  },
  {
    key: "symbol",
    label: "Symbol",
    align: "left",
    cellClassName: "font-medium text-foreground",
    getSortValue: (row) => row.symbol,
    getCopyValue: (row) => row.symbol,
    render: (row) => row.symbol,
  },
  {
    key: "name",
    label: "Name",
    align: "left",
    cellClassName: "text-muted-foreground",
    getSortValue: (row) => row.name,
    getCopyValue: (row) => row.name,
    render: (row) => row.name,
  },
  {
    key: "close",
    label: "Close",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.close,
    getCopyValue: (row) => formatNumber(row.close, priceFormatter),
    render: (row) => formatNumber(row.close, priceFormatter),
  },
  {
    key: "ma50",
    label: "MA 50",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.ma50,
    getCopyValue: (row) => formatNumber(row.ma50, priceFormatter),
    render: (row) => formatNumber(row.ma50, priceFormatter),
  },
  {
    key: "ma100",
    label: "MA 100",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.ma100,
    getCopyValue: (row) => formatNumber(row.ma100, priceFormatter),
    render: (row) => formatNumber(row.ma100, priceFormatter),
  },
  {
    key: "high20",
    label: "20w High",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.high20,
    getCopyValue: (row) => formatNumber(row.high20, priceFormatter),
    render: (row) => formatNumber(row.high20, priceFormatter),
  },
  {
    key: "high55",
    label: "55w High",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.high55,
    getCopyValue: (row) => formatNumber(row.high55, priceFormatter),
    render: (row) => formatNumber(row.high55, priceFormatter),
  },
  {
    key: "atr14",
    label: "ATR 14d",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.atr14,
    getCopyValue: (row) => formatNumber(row.atr14, priceFormatter),
    render: (row) => formatNumber(row.atr14, priceFormatter),
  },
  {
    key: "roc13",
    label: "ROC 13w (%)",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.roc13,
    getCopyValue: (row) => formatNumber(row.roc13, ratioFormatter, { suffix: "%" }),
    render: (row) => formatNumber(row.roc13, ratioFormatter, { suffix: "%" }),
  },
  {
    key: "avgVol20",
    label: "Avg Vol 20d",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.avgVol20,
    getCopyValue: (row) => formatNumber(row.avgVol20, integerFormatter),
    render: (row) => formatNumber(row.avgVol20, integerFormatter),
  },
  {
    key: "volVsAvg20",
    label: "Vol / Avg 20d",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.volVsAvg20,
    getCopyValue: (row) => formatRatio(row.volVsAvg20),
    render: (row) => formatRatio(row.volVsAvg20),
  },
  {
    key: "closeVsHigh20",
    label: "Close / 20wH",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.closeVsHigh20,
    getCopyValue: (row) => formatRatio(row.closeVsHigh20),
    render: (row) => formatRatio(row.closeVsHigh20),
  },
  {
    key: "closeVsHigh55",
    label: "Close / 55wH",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.closeVsHigh55,
    getCopyValue: (row) => formatRatio(row.closeVsHigh55),
    render: (row) => formatRatio(row.closeVsHigh55),
  },
  {
    key: "uptrend",
    label: "Uptrend",
    align: "left",
    getSortValue: (row) => (row.uptrend === null ? null : row.uptrend ? 1 : 0),
    getCopyValue: (row) =>
      row.uptrend === null ? "—" : row.uptrend ? "Yes" : "No",
    render: (row) =>
      row.uptrend === null ? (
        "—"
      ) : row.uptrend ? (
        <span className="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-600">
          Yes
        </span>
      ) : (
        <span className="rounded-full bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">
          No
        </span>
      ),
  },
  {
    key: "bars",
    label: "Bars",
    align: "right",
    cellClassName: "tabular-nums",
    getSortValue: (row) => row.bars,
    getCopyValue: (row) => formatNumber(row.bars, integerFormatter),
    render: (row) => formatNumber(row.bars, integerFormatter),
  },
]

const COLUMN_MAP = ASSET_METRIC_COLUMNS.reduce(
  (accumulator, column) => {
    accumulator[column.key] = column
    return accumulator
  },
  {} as Record<ColumnKey, ColumnDefinition>,
)

const TABLE_HEADERS = ASSET_METRIC_COLUMNS.map((column) => column.label)

export default function AssetsMetricsPage() {
  const { accessToken } = useAuth()
  const [rows, setRows] = useState<AssetMetricRow[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [copyStatus, setCopyStatus] = useState<"idle" | "success" | "error">("idle")
  const [copying, setCopying] = useState(false)
  const [sortRules, setSortRules] = useState<SortRule[]>([])

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

  const sortedRows = useMemo(() => {
    if (sortRules.length === 0) {
      return rows
    }

    const stabilized = rows.map((row, index) => ({ row, index }))

    const compareValues = (
      valueA: string | number | boolean | null,
      valueB: string | number | boolean | null,
      direction: SortDirection,
    ) => {
      if (valueA === valueB) {
        return 0
      }

      const isAscending = direction === "asc"

      if (valueA === null || valueA === undefined) {
        return isAscending ? 1 : -1
      }

      if (valueB === null || valueB === undefined) {
        return isAscending ? -1 : 1
      }

      if (typeof valueA === "string" && typeof valueB === "string") {
        return isAscending
          ? valueA.localeCompare(valueB)
          : valueB.localeCompare(valueA)
      }

      if (typeof valueA === "boolean" || typeof valueB === "boolean") {
        const numberA = valueA ? 1 : 0
        const numberB = valueB ? 1 : 0
        return isAscending ? numberA - numberB : numberB - numberA
      }

      const numberA = Number(valueA)
      const numberB = Number(valueB)

      if (Number.isNaN(numberA) || Number.isNaN(numberB)) {
        return 0
      }

      return isAscending ? numberA - numberB : numberB - numberA
    }

    stabilized.sort((a, b) => {
      for (const rule of sortRules) {
        const column = COLUMN_MAP[rule.key]
        if (!column) {
          continue
        }

        const result = compareValues(
          column.getSortValue(a.row),
          column.getSortValue(b.row),
          rule.direction,
        )

        if (result !== 0) {
          return result
        }
      }

      return a.index - b.index
    })

    return stabilized.map((item) => item.row)
  }, [rows, sortRules])

  const handleCopyTable = useCallback(async () => {
    if (sortedRows.length === 0) {
      return
    }

    if (typeof navigator === "undefined" || !navigator.clipboard) {
      setCopyStatus("error")
      return
    }

    setCopying(true)

    try {
      const tableText = [
        TABLE_HEADERS.join("\t"),
        ...sortedRows.map((row) =>
          ASSET_METRIC_COLUMNS.map((column) => column.getCopyValue(row)).join("\t"),
        ),
      ].join("\n")

      await navigator.clipboard.writeText(tableText)
      setCopyStatus("success")
    } catch (cause) {
      console.error("Failed to copy asset metrics table", cause)
      setCopyStatus("error")
    } finally {
      setCopying(false)
    }
  }, [sortedRows])

  const handleSortToggle = useCallback((columnKey: ColumnKey, isMultiSort: boolean) => {
    setSortRules((previous) => {
      const existingIndex = previous.findIndex((rule) => rule.key === columnKey)
      const existingRule = existingIndex >= 0 ? previous[existingIndex] : undefined
      const nextDirection: SortDirection | null = existingRule
        ? existingRule.direction === "asc"
          ? "desc"
          : existingRule.direction === "desc"
            ? null
            : "asc"
        : "asc"

      if (!isMultiSort) {
        return nextDirection ? [{ key: columnKey, direction: nextDirection }] : []
      }

      const withoutCurrent =
        existingIndex >= 0
          ? previous.filter((rule) => rule.key !== columnKey)
          : previous

      if (!nextDirection) {
        return withoutCurrent
      }

      return [...withoutCurrent, { key: columnKey, direction: nextDirection }]
    })
  }, [])

  useEffect(() => {
    if (copyStatus === "idle") {
      return
    }

    const timeout = window.setTimeout(
      () => setCopyStatus("idle"),
      copyStatus === "success" ? 2000 : 4000,
    )

    return () => {
      window.clearTimeout(timeout)
    }
  }, [copyStatus])

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

    const copyMessage =
      copyStatus === "success"
        ? "Table copied to your clipboard."
        : copyStatus === "error"
          ? "Unable to copy the table. Try again."
          : ""
    const copyButtonLabel =
      copying
        ? "Copying…"
        : copyStatus === "success"
          ? "Copied!"
          : "Copy table"

    return (
      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-col gap-1">
            <div className="min-h-[1.25rem] text-sm text-muted-foreground">{copyMessage}</div>
            <div className="text-xs text-muted-foreground">
              Click a column header to sort. Hold <span className="font-semibold">Shift</span> and click to
              set secondary sort priorities.
            </div>
          </div>
          <Button type="button" onClick={handleCopyTable} disabled={copying} variant="outline">
            {copyButtonLabel}
          </Button>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full border-separate border-spacing-0 overflow-hidden rounded-lg border bg-background text-sm shadow-sm">
            <thead className="bg-muted/60 text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                {ASSET_METRIC_COLUMNS.map((column) => {
                  const sortIndex = sortRules.findIndex((rule) => rule.key === column.key)
                  const direction = sortIndex >= 0 ? sortRules[sortIndex].direction : null
                  const alignmentClass = column.align === "right" ? "text-right" : "text-left"
                  const buttonAlignment = column.align === "right" ? "justify-end" : "justify-start"

                  const ariaLabel = `Sort by ${column.label}${direction ? `, currently ${direction === "asc" ? "ascending" : "descending"}` : ""}. Click to toggle sorting. Hold Shift and click to add or adjust secondary sort priority.`

                  return (
                    <th
                      key={column.key}
                      scope="col"
                      className={classNames(
                        "px-4 py-3 font-semibold",
                        alignmentClass,
                        column.headerStickyClassName,
                        column.headerClassName,
                      )}
                    >
                      <button
                        type="button"
                        onClick={(event) => handleSortToggle(column.key, event.shiftKey)}
                        className={classNames(
                          "flex w-full items-center gap-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2",
                          buttonAlignment,
                        )}
                        aria-label={ariaLabel}
                      >
                        <span>{column.label}</span>
                        <span className="flex items-center gap-1">
                          {sortIndex >= 0 ? (
                            <span className="inline-flex h-4 min-w-[1rem] items-center justify-center rounded bg-primary/10 px-1 text-[10px] font-semibold text-primary">
                              {sortIndex + 1}
                            </span>
                          ) : null}
                          {direction === "asc" ? (
                            <ArrowUp className="h-3.5 w-3.5" aria-hidden />
                          ) : direction === "desc" ? (
                            <ArrowDown className="h-3.5 w-3.5" aria-hidden />
                          ) : (
                            <ArrowUpDown className="h-3.5 w-3.5" aria-hidden />
                          )}
                        </span>
                      </button>
                    </th>
                  )
                })}
              </tr>
            </thead>
            <tbody>
              {sortedRows.map((row) => (
                <tr key={`${row.symbol}-${row.rank}`} className="border-t border-border/60">
                  {ASSET_METRIC_COLUMNS.map((column) => (
                    <td
                      key={column.key}
                      className={classNames(
                        "px-4 py-3",
                        column.align === "right" ? "text-right" : "text-left",
                        column.cellClassName,
                        column.cellStickyClassName,
                      )}
                    >
                      {column.render(row)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    )
  }, [
    copyStatus,
    copying,
    error,
    handleCopyTable,
    handleSortToggle,
    loading,
    rows,
    sortRules,
    sortedRows,
  ])

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
