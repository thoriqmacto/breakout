"use client"

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react"

import { useAuth } from "@/components/auth-provider"
import { Button, buttonVariants } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import { cn } from "@/lib/utils"
import { ArrowDown, ArrowUp } from "lucide-react"

const indexFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
})

const priceFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
})

const percentFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const DEFAULT_PAGE_SIZE = 30
const MAX_VISIBLE_SYMBOLS = 13
const VISIBLE_SYMBOLS_STORAGE_KEY = "tradingDaysVisibleSymbols"

type TradingDaySymbolPayload = {
  close: number | null
  change_percent: number | null
}

type TradingDayApiRow = {
  trading_date: string
  close: number | null
  change_percent: number | null
  symbols: Record<string, TradingDaySymbolPayload | undefined>
}

type TradingDaysApiResponse = {
  symbols: string[]
  trading_days: TradingDayApiRow[]
  pagination: {
    current_page: number
    per_page: number
    last_page: number
    total: number
  }
  statistics?: {
    total_valid_trading_days?: number
    highest_change?: { date?: string; change_percent?: number | null } | null
    lowest_change?: { date?: string; change_percent?: number | null } | null
  }
}

type TradingDayRow = {
  tradingDate: string
  close: number | null
  changePercent: number | null
  symbols: Record<string, { close: number | null; changePercent: number | null }>
}

type PaginationMeta = {
  currentPage: number
  perPage: number
  lastPage: number
  total: number
}

type TradingDayStatisticChange = {
  date: string
  changePercent: number
}

type TradingDayStatistics = {
  totalValidTradingDays: number
  highestChange: TradingDayStatisticChange | null
  lowestChange: TradingDayStatisticChange | null
}

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

const normalizeSymbols = (
  payload: Record<string, TradingDaySymbolPayload | undefined>,
): Record<string, { close: number | null; changePercent: number | null }> => {
  const entries = Object.entries(payload)
  const normalized: Record<string, { close: number | null; changePercent: number | null }> = {}

  for (const [symbol, value] of entries) {
    normalized[symbol] = {
      close: normalizeNumber(value?.close ?? null),
      changePercent: normalizeNumber(value?.change_percent ?? null),
    }
  }

  return normalized
}

const normalizeRow = (row: TradingDayApiRow): TradingDayRow => ({
  tradingDate: row.trading_date,
  close: normalizeNumber(row.close),
  changePercent: normalizeNumber(row.change_percent),
  symbols: normalizeSymbols(row.symbols ?? {}),
})

const normalizeChangeStatistic = (
  value: { date?: string; change_percent?: number | null } | null | undefined,
): TradingDayStatisticChange | null => {
  if (!value || typeof value.date !== "string") {
    return null
  }

  const change = normalizeNumber(value.change_percent ?? null)
  if (change === null) {
    return null
  }

  return {
    date: value.date,
    changePercent: change,
  }
}

const normalizeStatistics = (
  stats: TradingDaysApiResponse["statistics"] | undefined,
): TradingDayStatistics | null => {
  if (!stats) {
    return null
  }

  const total = normalizeNumber(stats.total_valid_trading_days ?? null)
  const highest = normalizeChangeStatistic(stats.highest_change)
  const lowest = normalizeChangeStatistic(stats.lowest_change)

  return {
    totalValidTradingDays: total !== null ? Math.max(0, Math.round(total)) : 0,
    highestChange: highest,
    lowestChange: lowest,
  }
}

const sanitizeVisibleSymbols = (candidate: string[], available: string[]) =>
  available.filter((symbol) => candidate.includes(symbol)).slice(0, MAX_VISIBLE_SYMBOLS)

const areArraysEqual = (first: string[], second: string[]) =>
  first.length === second.length && first.every((value, index) => value === second[index])

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
    console.error("Unable to read stored trading day symbols", error)
    return null
  }
}

export default function TradingDaysPage() {
  const { accessToken } = useAuth()
  const [rows, setRows] = useState<TradingDayRow[]>([])
  const [symbols, setSymbols] = useState<string[]>([])
  const [visibleSymbols, setVisibleSymbols] = useState<string[]>([])
  const [pagination, setPagination] = useState<PaginationMeta | null>(null)
  const [currentPage, setCurrentPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [statistics, setStatistics] = useState<TradingDayStatistics | null>(null)
  const [dateFilter, setDateFilter] = useState("")
  const [dateSort, setDateSort] = useState<"asc" | "desc">("desc")
  const [refreshNonce, setRefreshNonce] = useState(0)
  const [columnLimitMessage, setColumnLimitMessage] = useState<string | null>(null)

  useEffect(() => {
    if (symbols.length === 0) {
      setVisibleSymbols([])
      return
    }

    const stored = readStoredVisibleSymbols()
    const fallbackSelection = symbols.slice(0, MAX_VISIBLE_SYMBOLS)

    setVisibleSymbols((previous) => {
      const sanitizedPrevious = sanitizeVisibleSymbols(previous, symbols)
      const sanitizedStored = stored ? sanitizeVisibleSymbols(stored, symbols) : []

      const nextSelection =
        sanitizedPrevious.length > 0
          ? sanitizedPrevious
          : sanitizedStored.length > 0
            ? sanitizedStored
            : fallbackSelection

      return areArraysEqual(previous, nextSelection) ? previous : nextSelection
    })
  }, [symbols])

  useEffect(() => {
    if (symbols.length === 0 || typeof window === "undefined") {
      return
    }

    const sanitizedSelection = sanitizeVisibleSymbols(visibleSymbols, symbols)
    window.localStorage.setItem(VISIBLE_SYMBOLS_STORAGE_KEY, JSON.stringify(sanitizedSelection))
  }, [symbols, visibleSymbols])

  useEffect(() => {
    if (visibleSymbols.length < MAX_VISIBLE_SYMBOLS && columnLimitMessage) {
      setColumnLimitMessage(null)
    }
  }, [visibleSymbols, columnLimitMessage])

  useEffect(() => {
    if (!accessToken) {
      setRows([])
      setSymbols([])
      setPagination(null)
      setStatistics(null)
      return
    }

    const controller = new AbortController()
    setLoading(true)
    setError(null)

    const url = new URL(buildApiUrl("/v1/trading-days"))
    url.searchParams.set("page", String(currentPage))
    url.searchParams.set("per_page", String(DEFAULT_PAGE_SIZE))

    fetch(url.toString(), {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    })
      .then(async (response) => {
        const payload = await parseJson<ApiResponse<TradingDaysApiResponse>>(response)

        if (!response.ok) {
          const message =
            (payload && "message" in payload && payload.message) ||
            "Unable to load trading day data."
          throw new Error(
            typeof message === "string" ? message : "Unable to load trading day data.",
          )
        }

        if (!payload || payload.status !== "success") {
          const message =
            payload && "message" in payload && payload.message
              ? payload.message
              : "Unexpected response when fetching trading day data."
          throw new Error(
            typeof message === "string"
              ? message
              : "Unexpected response when fetching trading day data.",
          )
        }

        const data = payload.data
        if (!data || !Array.isArray(data.trading_days)) {
          throw new Error("Unexpected response format for trading days.")
        }

        setRows(data.trading_days.map((row) => normalizeRow(row)))
        setSymbols(Array.isArray(data.symbols) ? data.symbols.map((symbol) => symbol.toUpperCase()) : [])
        setPagination({
          currentPage: data.pagination?.current_page ?? currentPage,
          perPage: data.pagination?.per_page ?? DEFAULT_PAGE_SIZE,
          lastPage: data.pagination?.last_page ?? currentPage,
          total: data.pagination?.total ?? data.trading_days.length,
        })
        setStatistics(normalizeStatistics(data.statistics))
      })
      .catch((cause) => {
        if ((cause as Error)?.name === "AbortError") {
          return
        }

        const message =
          cause instanceof Error && cause.message
            ? cause.message
            : "Something went wrong while loading trading day data."
        setError(message)
        setRows([])
        setStatistics(null)
      })
      .finally(() => setLoading(false))

    return () => {
      controller.abort()
    }
  }, [accessToken, currentPage, refreshNonce])

  const toggleSymbol = useCallback(
    (symbol: string) => {
      let blockedAddition = false

      setVisibleSymbols((previous) => {
        if (previous.includes(symbol)) {
          return previous.filter((value) => value !== symbol)
        }

        if (previous.length >= MAX_VISIBLE_SYMBOLS) {
          blockedAddition = true
          return previous
        }

        const next = [...previous, symbol]
        return sanitizeVisibleSymbols(next, symbols)
      })

      setColumnLimitMessage(
        blockedAddition ? `You can show up to ${MAX_VISIBLE_SYMBOLS} symbol columns at once.` : null,
      )
    },
    [symbols],
  )

  const orderedVisibleSymbols = useMemo(
    () => symbols.filter((symbol) => visibleSymbols.includes(symbol)),
    [symbols, visibleSymbols],
  )

  const goToPreviousPage = useCallback(() => {
    setCurrentPage((previous) => Math.max(previous - 1, 1))
  }, [])

  const goToNextPage = useCallback(() => {
    setCurrentPage((previous) => {
      const lastPage = pagination?.lastPage ?? previous
      return Math.min(previous + 1, lastPage)
    })
  }, [pagination])

  const toggleDateSort = useCallback(() => {
    setDateSort((previous) => (previous === "desc" ? "asc" : "desc"))
  }, [])

  const clearDateFilter = useCallback(() => {
    setDateFilter("")
  }, [])

  const refreshTradingDays = useCallback(() => {
    setRefreshNonce((previous) => previous + 1)
  }, [])

  const filteredRows = useMemo(() => {
    const base = dateFilter ? rows.filter((row) => row.tradingDate === dateFilter) : rows
    const sorted = [...base]

    sorted.sort((a, b) =>
      dateSort === "asc"
        ? a.tradingDate.localeCompare(b.tradingDate)
        : b.tradingDate.localeCompare(a.tradingDate),
    )

    return sorted
  }, [rows, dateFilter, dateSort])

  const hasDateFilter = dateFilter.trim().length > 0

  const formatDate = (value: string) => {
    if (typeof value !== "string" || value.trim().length === 0) {
      return value
    }

    const parsed = new Date(value)
    if (Number.isNaN(parsed.getTime())) {
      return value
    }

    const year = parsed.getFullYear()
    const month = String(parsed.getMonth() + 1).padStart(2, "0")
    const day = String(parsed.getDate()).padStart(2, "0")

    return `${year}-${month}-${day}`
  }

  const formatCloseWithChange = (close: number | null, change: number | null) => {
    if (close === null || Number.isNaN(close)) {
      return "—"
    }

    const base = indexFormatter.format(close)

    if (change === null || Number.isNaN(change)) {
      return base
    }

    const className =
      change > 0
        ? "text-emerald-600"
        : change < 0
          ? "text-rose-600"
          : "text-muted-foreground"

    return (
      <div className="flex flex-col gap-0.5">
        <span className="font-medium tabular-nums">{base}</span>
        <span className={cn("text-xs font-medium tabular-nums", className)}>
          {change > 0 ? "+" : ""}
          {percentFormatter.format(change)}%
        </span>
      </div>
    )
  }

  const formatPriceWithChange = (close: number | null, change: number | null) => {
    if (close === null || Number.isNaN(close)) {
      return "—"
    }

    const base = priceFormatter.format(close)

    if (change === null || Number.isNaN(change)) {
      return base
    }

    const className =
      change > 0
        ? "text-emerald-600"
        : change < 0
          ? "text-rose-600"
          : "text-muted-foreground"

    return (
      <div className="flex flex-col gap-0.5">
        <span className="font-medium tabular-nums">{base}</span>
        <span className={cn("text-xs font-medium tabular-nums", className)}>
          {change > 0 ? "+" : ""}
          {percentFormatter.format(change)}%
        </span>
      </div>
    )
  }

  const canGoNext = pagination ? currentPage < pagination.lastPage : false
  const canGoPrevious = currentPage > 1

  const renderTableRows = () =>
    filteredRows.map((row) => {
      const symbolData = orderedVisibleSymbols.map((symbol) => ({
        symbol,
        data: row.symbols[symbol] ?? { close: null, changePercent: null },
      }))

      return (
        <tr key={row.tradingDate} className="bg-background">
          <td className="whitespace-nowrap px-4 py-3 font-medium">{formatDate(row.tradingDate)}</td>
          <td className="whitespace-nowrap px-4 py-3">
            {formatCloseWithChange(row.close, row.changePercent)}
          </td>
          {symbolData.map(({ symbol, data }) => (
            <td key={symbol} className="whitespace-nowrap px-4 py-3">
              {formatPriceWithChange(data.close ?? null, data.changePercent ?? null)}
            </td>
          ))}
        </tr>
      )
    })

  const renderTableContent = () => {
    if (error) {
      return (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive">
          {error}
        </div>
      )
    }

    if (loading) {
      return <div className="py-10 text-center text-sm text-muted-foreground">Loading trading day data...</div>
    }

    if (rows.length === 0) {
      return <div className="py-10 text-center text-sm text-muted-foreground">No trading day records available.</div>
    }

    const hasFilteredResults = filteredRows.length > 0

    return (
      <div className="space-y-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div className="flex items-end gap-2">
            <div className="flex flex-col gap-1">
              <label htmlFor="trading-date-filter" className="text-sm font-medium">
                Filter by date
              </label>
              <Input
                id="trading-date-filter"
                type="date"
                value={dateFilter}
                onChange={(event) => setDateFilter(event.target.value)}
                className="h-9 w-44"
              />
            </div>
            {hasDateFilter ? (
              <Button type="button" variant="ghost" size="sm" onClick={clearDateFilter}>
                Clear
              </Button>
            ) : null}
          </div>
          <PaginationControls />
        </div>

        {hasFilteredResults ? (
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
                      {renderSortIcon()}
                      <span className="sr-only">Toggle trading date sort</span>
                    </button>
                  </th>
                  <th className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    IHSG (%CHG)
                  </th>
                  {orderedVisibleSymbols.map((symbol) => (
                    <th
                      key={symbol}
                      className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                    >
                      {symbol} (%CHG)
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-border">{renderTableRows()}</tbody>
            </table>
          </div>
        ) : (
          <div className="rounded-md border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground">
            No trading days match the selected date.
          </div>
        )}
      </div>
    )
  }

  const renderSortIcon = () => {
    if (dateSort === "asc") {
      return <ArrowUp className="h-3.5 w-3.5" aria-hidden />
    }

    return <ArrowDown className="h-3.5 w-3.5" aria-hidden />
  }

  const PaginationControls = ({ className }: { className?: string }) => {
    const [goToPageValue, setGoToPageValue] = useState("")

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault()

      if (!pagination) {
        return
      }

      const parsed = Number.parseInt(goToPageValue, 10)
      if (Number.isNaN(parsed)) {
        return
      }

      const nextPage = Math.min(Math.max(parsed, 1), pagination.lastPage)
      setCurrentPage(nextPage)
      setGoToPageValue("")
    }

    if (!pagination || rows.length === 0) {
      return null
    }

    return (
      <div
        className={cn(
          "flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between",
          className,
        )}
      >
        <div>
          Page {pagination.currentPage} of {pagination.lastPage}
        </div>
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
          <form className="flex items-center gap-2" onSubmit={handleSubmit}>
            <Input
              type="number"
              min={1}
              max={pagination.lastPage}
              value={goToPageValue}
              onChange={(event) => setGoToPageValue(event.target.value)}
              placeholder="Page"
              inputMode="numeric"
              aria-label="Go to page"
              className="h-9 w-20"
            />
            <Button type="submit" variant="outline" size="sm" disabled={goToPageValue.trim().length === 0}>
              Go
            </Button>
          </form>
          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={goToPreviousPage}
              disabled={!canGoPrevious}
            >
              Previous
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={goToNextPage}
              disabled={!canGoNext}
            >
              Next
            </Button>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between sm:space-y-0">
          <div className="space-y-1">
            <CardTitle>Trading Days</CardTitle>
            <CardDescription>
              Review IHSG performance and key closing prices for recent trading sessions.
            </CardDescription>
          </div>
          <div className="flex items-center gap-2">
            <Button type="button" variant="outline" size="sm" onClick={refreshTradingDays} disabled={loading}>
              Refresh data
            </Button>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {statistics ? (
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="rounded-lg border bg-muted/40 p-4">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  Total Valid Trading Days
                </p>
                <p className="mt-1 text-2xl font-semibold">
                  {statistics.totalValidTradingDays.toLocaleString("en-US")}
                </p>
              </div>
              <div className="rounded-lg border bg-muted/40 p-4">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  Highest IHSG % Change
                </p>
                {statistics.highestChange ? (
                  <div className="mt-1 space-y-1 text-sm">
                    <p className="font-semibold tabular-nums text-emerald-600">
                      {statistics.highestChange.changePercent > 0 ? "+" : ""}
                      {percentFormatter.format(statistics.highestChange.changePercent)}%
                    </p>
                    <p className="text-muted-foreground">{formatDate(statistics.highestChange.date)}</p>
                  </div>
                ) : (
                  <p className="mt-1 text-sm text-muted-foreground">Not enough data</p>
                )}
              </div>
              <div className="rounded-lg border bg-muted/40 p-4">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  Lowest IHSG % Change
                </p>
                {statistics.lowestChange ? (
                  <div className="mt-1 space-y-1 text-sm">
                    <p className="font-semibold tabular-nums text-rose-600">
                      {statistics.lowestChange.changePercent > 0 ? "+" : ""}
                      {percentFormatter.format(statistics.lowestChange.changePercent)}%
                    </p>
                    <p className="text-muted-foreground">{formatDate(statistics.lowestChange.date)}</p>
                  </div>
                ) : (
                  <p className="mt-1 text-sm text-muted-foreground">Not enough data</p>
                )}
              </div>
            </div>
          ) : null}

          {symbols.length > 0 ? (
            <div className="space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium">Symbols</span>
                <div className="flex flex-wrap items-center gap-2">
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
              </div>
              <p className="text-xs text-muted-foreground">
                Choose up to {MAX_VISIBLE_SYMBOLS} symbol columns. Your selection is saved as the default view.
              </p>
              {columnLimitMessage ? (
                <p className="text-xs font-medium text-amber-600">{columnLimitMessage}</p>
              ) : null}
            </div>
          ) : null}

          {renderTableContent()}
          <PaginationControls className="border-t pt-4" />
        </CardContent>
      </Card>
    </div>
  )
}
