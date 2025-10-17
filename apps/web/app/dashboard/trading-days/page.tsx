"use client"

import { useCallback, useEffect, useMemo, useState } from "react"

import { useAuth } from "@/components/auth-provider"
import { Button, buttonVariants } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import { cn } from "@/lib/utils"

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

const dateFormatter = new Intl.DateTimeFormat("en-US", {
  year: "numeric",
  month: "short",
  day: "2-digit",
})

const DEFAULT_PAGE_SIZE = 30

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

export default function TradingDaysPage() {
  const { accessToken } = useAuth()
  const [rows, setRows] = useState<TradingDayRow[]>([])
  const [symbols, setSymbols] = useState<string[]>([])
  const [visibleSymbols, setVisibleSymbols] = useState<string[]>([])
  const [pagination, setPagination] = useState<PaginationMeta | null>(null)
  const [currentPage, setCurrentPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (symbols.length === 0) {
      setVisibleSymbols([])
      return
    }

    setVisibleSymbols((previous) => {
      if (previous.length === 0) {
        return symbols
      }

      const filtered = previous.filter((symbol) => symbols.includes(symbol))
      if (filtered.length === previous.length && filtered.length > 0) {
        return filtered
      }

      return filtered.length > 0 ? filtered : symbols
    })
  }, [symbols])

  useEffect(() => {
    if (!accessToken) {
      setRows([])
      setSymbols([])
      setPagination(null)
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
      })
      .finally(() => setLoading(false))

    return () => {
      controller.abort()
    }
  }, [accessToken, currentPage])

  const toggleSymbol = useCallback(
    (symbol: string) => {
      setVisibleSymbols((previous) => {
        if (previous.includes(symbol)) {
          return previous.filter((value) => value !== symbol)
        }

        const next = [...previous, symbol]
        return symbols.filter((value) => next.includes(value))
      })
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

  const formatDate = (value: string) => {
    try {
      return dateFormatter.format(new Date(value))
    } catch {
      return value
    }
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

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="gap-1">
          <CardTitle>Trading Days</CardTitle>
          <CardDescription>
            Review IHSG performance and key closing prices for recent trading sessions.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {symbols.length > 0 ? (
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
                    >
                      {symbol}
                    </button>
                  )
                })}
              </div>
            </div>
          ) : null}

          {error ? (
            <div className="rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive">
              {error}
            </div>
          ) : loading ? (
            <div className="py-10 text-center text-sm text-muted-foreground">
              Loading trading day data...
            </div>
          ) : rows.length === 0 ? (
            <div className="py-10 text-center text-sm text-muted-foreground">
              No trading day records available.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-border text-sm">
                <thead className="bg-muted/60">
                  <tr>
                    <th className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      Trading Date
                    </th>
                    <th className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      IHSG (%Change)
                    </th>
                    {orderedVisibleSymbols.map((symbol) => (
                      <th
                        key={symbol}
                        className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                      >
                        {symbol} Close (%Change)
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {rows.map((row) => {
                    const symbolData = orderedVisibleSymbols.map((symbol) => ({
                      symbol,
                      data: row.symbols[symbol] ?? { close: null, changePercent: null },
                    }))

                    return (
                      <tr key={row.tradingDate} className="bg-background">
                        <td className="whitespace-nowrap px-4 py-3 font-medium">
                          {formatDate(row.tradingDate)}
                        </td>
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
                  })}
                </tbody>
              </table>
            </div>
          )}

          {pagination && rows.length > 0 ? (
            <div className="flex flex-col gap-2 border-t pt-4 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
              <div>
                Page {pagination.currentPage} of {pagination.lastPage}
              </div>
              <div className="flex items-center gap-2">
                <Button type="button" variant="outline" size="sm" onClick={goToPreviousPage} disabled={!canGoPrevious}>
                  Previous
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={goToNextPage} disabled={!canGoNext}>
                  Next
                </Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
