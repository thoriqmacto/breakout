"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { ArrowDown, ArrowUp } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button, buttonVariants } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import { cn } from "@/lib/utils"

type Asset = {
  id: number
  symbol: string
}

type BroksumRow = {
  date: string
  broker: string
  buy_value: number | null
  buy_avg_price: number | null
}

type BrokerSummaryApiResponse = {
  symbol: string
  rows: BroksumRow[]
}

type BrokerRank = {
  broker: string
  avgBuyPrice: number | null
  totalValue: number | null
  percentage: number | null
}

type DateSymbolSummary = {
  symbol: string
  topBrokers: BrokerRank[]
}

type DailySummaryRow = {
  tradingDate: string
  symbols: Record<string, DateSymbolSummary>
}

const MAX_VISIBLE_SYMBOLS = 8
const VISIBLE_SYMBOLS_STORAGE_KEY = "brokerSummaryVisibleSymbols"
const MAX_ROWS_PER_SYMBOL = 1000

const currencyFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
})

const percentFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const normalizeNumber = (value: unknown): number | null => {
  if (typeof value === "number") {
    return Number.isFinite(value) ? value : null
  }

  if (typeof value === "string") {
    const trimmed = value.trim()
    if (!trimmed) {
      return null
    }

    const parsed = Number.parseFloat(trimmed)
    return Number.isNaN(parsed) ? null : parsed
  }

  return null
}

const sanitizeVisibleSymbols = (candidate: string[], available: string[]) =>
  available.filter((symbol) => candidate.includes(symbol)).slice(0, MAX_VISIBLE_SYMBOLS)

const readStoredVisibleSymbols = (): string[] | null => {
  if (typeof window === "undefined") {
    return null
  }

  const raw = window.localStorage.getItem(VISIBLE_SYMBOLS_STORAGE_KEY)
  if (!raw) {
    return null
  }

  try {
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) {
      return null
    }

    const sanitized = parsed.filter((value): value is string => typeof value === "string")
    return sanitized.length > 0 ? sanitized : null
  } catch (error) {
    console.error("Unable to read broker summary column settings", error)
    return null
  }
}

const formatDate = (value: string) => {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) {
    return value
  }

  const year = parsed.getFullYear()
  const month = String(parsed.getMonth() + 1).padStart(2, "0")
  const day = String(parsed.getDate()).padStart(2, "0")

  return `${year}-${month}-${day}`
}

const buildDailyRows = (
  selectedSymbols: string[],
  bySymbolRows: Record<string, BroksumRow[]>,
): DailySummaryRow[] => {
  const byDate: Map<string, DailySummaryRow> = new Map()

  for (const symbol of selectedSymbols) {
    const rows = bySymbolRows[symbol] ?? []
    const rowsByDate = new Map<string, BroksumRow[]>()

    for (const row of rows) {
      const date = row.date
      if (!rowsByDate.has(date)) {
        rowsByDate.set(date, [])
      }

      rowsByDate.get(date)?.push(row)
    }

    for (const [date, dateRows] of rowsByDate.entries()) {
      const totalDailyBuyValue = dateRows.reduce((sum, item) => sum + (normalizeNumber(item.buy_value) ?? 0), 0)
      const sorted = [...dateRows].sort(
        (a, b) => (normalizeNumber(b.buy_value) ?? 0) - (normalizeNumber(a.buy_value) ?? 0),
      )

      const topBrokers: BrokerRank[] = sorted.slice(0, 3).map((entry) => {
        const buyValue = normalizeNumber(entry.buy_value)
        const ratio =
          buyValue !== null && totalDailyBuyValue > 0 ? (buyValue / totalDailyBuyValue) * 100 : null

        return {
          broker: entry.broker,
          avgBuyPrice: normalizeNumber(entry.buy_avg_price),
          totalValue: buyValue,
          percentage: ratio,
        }
      })

      const existing = byDate.get(date) ?? { tradingDate: date, symbols: {} }
      existing.symbols[symbol] = { symbol, topBrokers }
      byDate.set(date, existing)
    }
  }

  return Array.from(byDate.values())
}

export default function BrokerSummaryPage() {
  const { accessToken } = useAuth()
  const [assets, setAssets] = useState<Asset[]>([])
  const [visibleSymbols, setVisibleSymbols] = useState<string[]>([])
  const [rows, setRows] = useState<DailySummaryRow[]>([])
  const [dateFilter, setDateFilter] = useState("")
  const [fromDate, setFromDate] = useState("")
  const [toDate, setToDate] = useState("")
  const [dateSort, setDateSort] = useState<"asc" | "desc">("desc")
  const [error, setError] = useState<string | null>(null)
  const [loadingAssets, setLoadingAssets] = useState(false)
  const [loadingRows, setLoadingRows] = useState(false)

  const symbols = useMemo(() => assets.map((asset) => asset.symbol), [assets])

  useEffect(() => {
    if (symbols.length === 0) {
      setVisibleSymbols([])
      return
    }

    const stored = readStoredVisibleSymbols()
    const sanitizedStored = stored ? sanitizeVisibleSymbols(stored, symbols) : []
    const fallback = symbols.slice(0, Math.min(MAX_VISIBLE_SYMBOLS, symbols.length))

    setVisibleSymbols((previous) => {
      const sanitizedPrevious = sanitizeVisibleSymbols(previous, symbols)
      if (sanitizedPrevious.length > 0) {
        return sanitizedPrevious
      }

      return sanitizedStored.length > 0 ? sanitizedStored : fallback
    })
  }, [symbols])

  useEffect(() => {
    if (symbols.length === 0 || typeof window === "undefined") {
      return
    }

    const sanitized = sanitizeVisibleSymbols(visibleSymbols, symbols)
    window.localStorage.setItem(VISIBLE_SYMBOLS_STORAGE_KEY, JSON.stringify(sanitized))
  }, [symbols, visibleSymbols])

  useEffect(() => {
    if (!accessToken) {
      setAssets([])
      return
    }

    const controller = new AbortController()
    setLoadingAssets(true)

    fetch(buildApiUrl("/v1/assets"), {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    })
      .then(async (response) => {
        const payload = await parseJson<ApiResponse<Asset[]>>(response)

        if (!response.ok || !payload || payload.status !== "success") {
          throw new Error("Unable to load assets for broker summary")
        }

        const incomingAssets = Array.isArray(payload.data) ? payload.data : []
        const sanitized = incomingAssets
          .map((asset) => ({
            id: Number(asset.id),
            symbol: String(asset.symbol ?? "").toUpperCase(),
          }))
          .filter((asset) => asset.symbol.length > 0)
          .sort((a, b) => a.symbol.localeCompare(b.symbol))

        setAssets(sanitized)
      })
      .catch((cause) => {
        if ((cause as Error)?.name === "AbortError") {
          return
        }

        console.error(cause)
        setAssets([])
      })
      .finally(() => setLoadingAssets(false))

    return () => controller.abort()
  }, [accessToken])

  useEffect(() => {
    if (!accessToken || visibleSymbols.length === 0) {
      setRows([])
      return
    }

    const controller = new AbortController()
    setLoadingRows(true)
    setError(null)

    Promise.all(
      visibleSymbols.map(async (symbol) => {
        const url = new URL(buildApiUrl("/v1/broker-summaries"))
        url.searchParams.set("symbol", symbol)
        url.searchParams.set("limit", String(MAX_ROWS_PER_SYMBOL))

        if (fromDate) {
          url.searchParams.set("from", fromDate)
        }

        if (toDate) {
          url.searchParams.set("to", toDate)
        }

        const response = await fetch(url.toString(), {
          method: "GET",
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${accessToken}`,
          },
          signal: controller.signal,
        })

        const payload = await parseJson<ApiResponse<BrokerSummaryApiResponse>>(response)

        if (!response.ok || !payload || payload.status !== "success") {
          throw new Error(`Unable to load broker summary for ${symbol}`)
        }

        return {
          symbol,
          rows: Array.isArray(payload.data?.rows) ? payload.data.rows : [],
        }
      }),
    )
      .then((responses) => {
        const bySymbolRows: Record<string, BroksumRow[]> = {}

        for (const response of responses) {
          bySymbolRows[response.symbol] = response.rows
        }

        setRows(buildDailyRows(visibleSymbols, bySymbolRows))
      })
      .catch((cause) => {
        if ((cause as Error)?.name === "AbortError") {
          return
        }

        const message =
          cause instanceof Error && cause.message
            ? cause.message
            : "Unable to load broker summary data."

        setError(message)
        setRows([])
      })
      .finally(() => setLoadingRows(false))

    return () => controller.abort()
  }, [accessToken, fromDate, toDate, visibleSymbols])

  const orderedVisibleSymbols = useMemo(
    () => symbols.filter((symbol) => visibleSymbols.includes(symbol)),
    [symbols, visibleSymbols],
  )

  const filteredRows = useMemo(() => {
    const base = dateFilter ? rows.filter((row) => row.tradingDate === dateFilter) : rows
    const sorted = [...base]

    sorted.sort((a, b) =>
      dateSort === "asc"
        ? a.tradingDate.localeCompare(b.tradingDate)
        : b.tradingDate.localeCompare(a.tradingDate),
    )

    return sorted
  }, [dateFilter, dateSort, rows])

  const toggleDateSort = useCallback(() => {
    setDateSort((previous) => (previous === "desc" ? "asc" : "desc"))
  }, [])

  const toggleSymbol = useCallback(
    (symbol: string) => {
      setVisibleSymbols((previous) => {
        if (previous.includes(symbol)) {
          return previous.filter((value) => value !== symbol)
        }

        if (previous.length >= MAX_VISIBLE_SYMBOLS) {
          return previous
        }

        return sanitizeVisibleSymbols([...previous, symbol], symbols)
      })
    },
    [symbols],
  )

  const formatValue = (value: number | null) => {
    if (value === null || Number.isNaN(value)) {
      return "—"
    }

    return currencyFormatter.format(value)
  }

  const formatPercentage = (value: number | null) => {
    if (value === null || Number.isNaN(value)) {
      return "—"
    }

    return `${percentFormatter.format(value)}%`
  }

  const renderCell = (row: DailySummaryRow, symbol: string) => {
    const summary = row.symbols[symbol]
    if (!summary || summary.topBrokers.length === 0) {
      return <span className="text-muted-foreground">—</span>
    }

    return (
      <div className="space-y-2">
        {summary.topBrokers.map((broker) => (
          <div key={`${summary.symbol}-${broker.broker}`} className="rounded border p-2">
            <p className="text-xs font-semibold">{broker.broker}</p>
            <p className="text-xs text-muted-foreground">Avg buy: {formatValue(broker.avgBuyPrice)}</p>
            <p className="text-xs text-muted-foreground">Total value: {formatValue(broker.totalValue)}</p>
            <p className="text-xs text-muted-foreground">Daily ratio: {formatPercentage(broker.percentage)}</p>
          </div>
        ))}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Broker Summary</CardTitle>
          <CardDescription>
            Compare daily top brokers across selected assets and inspect each broker&apos;s buy contribution.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex items-end gap-2">
              <div className="flex flex-col gap-1">
                <label htmlFor="broker-summary-date-filter" className="text-sm font-medium">
                  Filter by date
                </label>
                <Input
                  id="broker-summary-date-filter"
                  type="date"
                  value={dateFilter}
                  onChange={(event) => setDateFilter(event.target.value)}
                  className="h-9 w-44"
                />
              </div>
              {dateFilter ? (
                <Button type="button" variant="ghost" size="sm" onClick={() => setDateFilter("")}>
                  Clear
                </Button>
              ) : null}
            </div>

            <div className="flex items-end gap-2">
              <div className="flex flex-col gap-1">
                <label htmlFor="broker-summary-from" className="text-sm font-medium">
                  From
                </label>
                <Input
                  id="broker-summary-from"
                  type="date"
                  value={fromDate}
                  onChange={(event) => setFromDate(event.target.value)}
                  className="h-9 w-40"
                />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor="broker-summary-to" className="text-sm font-medium">
                  To
                </label>
                <Input
                  id="broker-summary-to"
                  type="date"
                  value={toDate}
                  onChange={(event) => setToDate(event.target.value)}
                  className="h-9 w-40"
                />
              </div>
            </div>
          </div>

          <div className="space-y-2">
            <span className="text-sm font-medium">Assets</span>
            <div className="flex flex-wrap gap-2">
              {symbols.map((symbol) => {
                const isActive = orderedVisibleSymbols.includes(symbol)
                return (
                  <button
                    key={symbol}
                    type="button"
                    onClick={() => toggleSymbol(symbol)}
                    className={cn(
                      buttonVariants({
                        variant: isActive ? "secondary" : "outline",
                        size: "sm",
                      }),
                      "px-3",
                    )}
                    aria-pressed={isActive}
                    disabled={!isActive && orderedVisibleSymbols.length >= MAX_VISIBLE_SYMBOLS}
                  >
                    {symbol}
                  </button>
                )
              })}
            </div>
            <p className="text-xs text-muted-foreground">
              Choose up to {MAX_VISIBLE_SYMBOLS} assets to show as columns.
            </p>
          </div>

          {error ? (
            <div className="rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive">
              {error}
            </div>
          ) : null}

          {loadingAssets || loadingRows ? (
            <div className="py-10 text-center text-sm text-muted-foreground">Loading broker summary data...</div>
          ) : filteredRows.length === 0 ? (
            <div className="rounded-md border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground">
              No broker summary records available for the selected criteria.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-border text-sm">
                <thead className="bg-muted/60">
                  <tr>
                    <th className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      <button
                        type="button"
                        onClick={toggleDateSort}
                        className="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                      >
                        <span>Trading Date</span>
                        {dateSort === "asc" ? (
                          <ArrowUp className="h-3.5 w-3.5" aria-hidden />
                        ) : (
                          <ArrowDown className="h-3.5 w-3.5" aria-hidden />
                        )}
                      </button>
                    </th>
                    {orderedVisibleSymbols.map((symbol) => (
                      <th
                        key={symbol}
                        className="min-w-[240px] whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                      >
                        {symbol}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {filteredRows.map((row) => (
                    <tr key={row.tradingDate}>
                      <td className="whitespace-nowrap px-4 py-3 font-medium">{formatDate(row.tradingDate)}</td>
                      {orderedVisibleSymbols.map((symbol) => (
                        <td key={`${row.tradingDate}-${symbol}`} className="align-top px-4 py-3">
                          {renderCell(row, symbol)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
