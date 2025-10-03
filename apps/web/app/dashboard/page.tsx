"use client"

import { useEffect, useRef, useState } from "react"

import { useAuth } from "@/components/auth-provider"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

const parseNumericInput = (value: string) => {
  if (!value?.trim()) {
    return Number.NaN
  }

  const parsed = Number.parseFloat(value.replace(/,/g, ""))
  return Number.isNaN(parsed) ? Number.NaN : parsed
}

const getIdxTickSize = (price: number) => {
  if (!Number.isFinite(price) || price <= 0) {
    return 0
  }

  if (price < 200) return 1
  if (price < 500) return 2
  if (price < 2000) return 5
  if (price < 5000) return 10
  return 25
}

const applyIdxTickRule = (price: number) => {
  if (!Number.isFinite(price) || price <= 0) {
    return null
  }

  let adjusted = price
  let iterations = 0

  while (iterations < 5) {
    const tick = getIdxTickSize(adjusted)
    if (tick <= 0) {
      return null
    }

    const next = Math.floor(adjusted / tick) * tick
    if (next <= 0) {
      return tick
    }

    if (Math.abs(next - adjusted) < Number.EPSILON) {
      return Number(next.toFixed(2))
    }

    adjusted = next
    iterations += 1
  }

  return Number(adjusted.toFixed(2))
}

const formatIdr = (value: number) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(value)

const highlights = [
  {
    title: "Total Strategies",
    value: "6",
    description: "Configured breakout strategies currently monitored.",
  },
  {
    title: "Open Alerts",
    value: "12",
    description: "Signals awaiting analyst review across all assets.",
  },
  {
    title: "Last Sync",
    value: "3m ago",
    description: "Time since the asset metrics pipeline completed.",
  },
]

type LatestPriceRecord = {
  id: number
  asset_id: number
  date: string | null
  open: number | null
  high: number | null
  low: number | null
  close: number | string | null
  volume: number | null
  asset?: {
    id: number
    symbol: string
    name: string
  } | null
}

export default function DashboardPage() {
  const { user, accessToken } = useAuth()
  const [symbol, setSymbol] = useState("")
  const [closePriceInput, setClosePriceInput] = useState("")
  const [percentInput, setPercentInput] = useState("")
  const [priceInput, setPriceInput] = useState("")
  const closePriceTouchedRef = useRef(false)
  const [debouncedSymbol, setDebouncedSymbol] = useState("")
  const [latestPriceLoading, setLatestPriceLoading] = useState(false)
  const [latestPriceError, setLatestPriceError] = useState<string | null>(null)
  const [latestPriceClose, setLatestPriceClose] = useState<number | null>(null)
  const [latestPriceDate, setLatestPriceDate] = useState<string | null>(null)
  const [lastFetchedSymbol, setLastFetchedSymbol] = useState<string | null>(null)
  const [priceRefreshCounter, setPriceRefreshCounter] = useState(0)

  useEffect(() => {
    const trimmed = symbol.trim()

    if (!trimmed) {
      setDebouncedSymbol("")
      return
    }

    const timeoutId = window.setTimeout(() => {
      setDebouncedSymbol(trimmed)
    }, 400)

    return () => {
      window.clearTimeout(timeoutId)
    }
  }, [symbol])

  useEffect(() => {
    if (!debouncedSymbol) {
      setLatestPriceLoading(false)
      setLatestPriceError(null)
      setLatestPriceClose(null)
      setLatestPriceDate(null)
      setLastFetchedSymbol(null)
      return
    }

    if (!accessToken) {
      setLatestPriceLoading(false)
      setLatestPriceError("Sign in to fetch the latest close price.")
      setLatestPriceClose(null)
      setLatestPriceDate(null)
      setLastFetchedSymbol(debouncedSymbol)
      return
    }

    let isCancelled = false
    const controller = new AbortController()

    setLatestPriceLoading(true)
    setLatestPriceError(null)

    const loadLatestPrice = async () => {
      try {
        const response = await fetch(buildApiUrl("/v1/assets/latest-prices"), {
          method: "GET",
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${accessToken}`,
          },
          signal: controller.signal,
        })

        const payload = await parseJson<ApiResponse<LatestPriceRecord[]>>(response)

        if (!response.ok) {
          const message =
            (payload && "message" in payload && typeof payload.message === "string" && payload.message) ||
            "Unable to fetch the latest close price."

          throw new Error(message)
        }

        if (!payload || payload.status !== "success" || !Array.isArray(payload.data)) {
          throw new Error("Unexpected response from the asset API.")
        }

        const match = payload.data.find(
          (record) => record?.asset?.symbol?.toUpperCase() === debouncedSymbol,
        )

        if (!match) {
          if (isCancelled) {
            return
          }

          setLatestPriceError(`No latest close price found for ${debouncedSymbol}.`)
          setLatestPriceClose(null)
          setLatestPriceDate(null)
          setLastFetchedSymbol(debouncedSymbol)
          return
        }

        const close =
          typeof match.close === "number"
            ? match.close
            : Number.parseFloat(match.close ? String(match.close) : "")

        if (!Number.isFinite(close)) {
          if (isCancelled) {
            return
          }

          setLatestPriceError(`Latest close price for ${debouncedSymbol} is unavailable.`)
          setLatestPriceClose(null)
          setLatestPriceDate(match.date ?? null)
          setLastFetchedSymbol(match.asset?.symbol ?? debouncedSymbol)
          return
        }

        if (isCancelled) {
          return
        }

        setLatestPriceClose(close)
        setLatestPriceDate(match.date ?? null)
        setLastFetchedSymbol(match.asset?.symbol ?? debouncedSymbol)
        setLatestPriceError(null)

        if (!closePriceTouchedRef.current) {
          setClosePriceInput(close.toString())
        }
      } catch (error) {
        if (isCancelled || (error instanceof Error && error.name === "AbortError")) {
          return
        }

        const message =
          error instanceof Error ? error.message : "Unable to fetch the latest close price."

        setLatestPriceError(message)
        setLatestPriceClose(null)
        setLatestPriceDate(null)
        setLastFetchedSymbol(debouncedSymbol)
      } finally {
        if (!isCancelled) {
          setLatestPriceLoading(false)
        }
      }
    }

    void loadLatestPrice()

    return () => {
      isCancelled = true
      controller.abort()
    }
  }, [accessToken, debouncedSymbol, priceRefreshCounter])

  const closePrice = parseNumericInput(closePriceInput)
  const trailingPercent = parseNumericInput(percentInput)
  const trailingPriceRaw = parseNumericInput(priceInput)

  const trailingPriceFromPercent =
    Number.isFinite(closePrice) && closePrice > 0 && Number.isFinite(trailingPercent)
      ? applyIdxTickRule(closePrice * (1 - trailingPercent / 100))
      : null

  const tickSizeFromPercent =
    trailingPriceFromPercent !== null ? getIdxTickSize(trailingPriceFromPercent) : null

  const percentFromPrice =
    Number.isFinite(closePrice) && closePrice > 0 && Number.isFinite(trailingPriceRaw)
      ? (() => {
          const adjustedPrice = applyIdxTickRule(trailingPriceRaw)
          if (adjustedPrice === null || adjustedPrice <= 0) {
            return null
          }

          const percent = ((closePrice - adjustedPrice) / closePrice) * 100
          return percent < 0 ? 0 : percent
        })()
      : null

  const adjustedPriceFromInput =
    Number.isFinite(trailingPriceRaw) && trailingPriceRaw > 0 ? applyIdxTickRule(trailingPriceRaw) : null

  const tickSizeFromPrice =
    adjustedPriceFromInput !== null && adjustedPriceFromInput > 0 ? getIdxTickSize(adjustedPriceFromInput) : null

  const stopDistance =
    trailingPriceFromPercent !== null && Number.isFinite(closePrice) && closePrice > 0
      ? closePrice - trailingPriceFromPercent
      : null

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-3xl font-semibold tracking-tight">Welcome back, {user?.name.split(" ")[0] ?? ""}</h1>
        <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
          Use this dashboard to review analytics from the Breakout API and manage operational workflows across your
          trading strategies.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {highlights.map((item) => (
          <Card key={item.title}>
            <CardHeader>
              <CardTitle className="text-base font-semibold">{item.title}</CardTitle>
              <CardDescription>{item.description}</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-3xl font-semibold">{item.value}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <Card>
        <CardHeader>
          <CardTitle>Trailing stop calculator</CardTitle>
          <CardDescription>
            Convert trailing stop percentages to IDX-compliant prices and back using the official tick size rule.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="space-y-3 rounded-lg border border-dashed p-4">
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground" htmlFor="symbol">
                Symbol
              </label>
              <Input
                id="symbol"
                placeholder="e.g. BBCA"
                value={symbol}
                onChange={(event) => {
                  const value = event.target.value.toUpperCase()
                  setSymbol(value)
                  closePriceTouchedRef.current = false
                  setLatestPriceError(null)
                  setLatestPriceClose(null)
                  setLatestPriceDate(null)
                  setLastFetchedSymbol(null)
                }}
                autoComplete="off"
              />
            </div>
            <div className="space-y-2">
              <div className="flex items-end gap-2">
                <div className="flex-1 space-y-2">
                  <label className="text-sm font-medium text-foreground" htmlFor="close-price">
                    Latest close price (IDR)
                  </label>
                  <Input
                    id="close-price"
                    inputMode="decimal"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="Enter the latest close"
                    value={closePriceInput}
                    onChange={(event) => {
                      setClosePriceInput(event.target.value)
                      closePriceTouchedRef.current = true
                    }}
                  />
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    closePriceTouchedRef.current = false
                    setPriceRefreshCounter((count) => count + 1)
                  }}
                  disabled={!symbol.trim() || latestPriceLoading || !accessToken}
                >
                  {latestPriceLoading ? "Loading..." : "Refresh"}
                </Button>
              </div>
              <p className="min-h-[1.25rem] text-xs text-muted-foreground">
                {latestPriceLoading ? (
                  "Fetching the latest close price..."
                ) : latestPriceError ? (
                  <span className="text-destructive">{latestPriceError}</span>
                ) : latestPriceClose !== null ? (
                  <span>
                    Latest close for {" "}
                    <span className="font-medium text-foreground">
                      {lastFetchedSymbol ?? (debouncedSymbol || symbol)}
                    </span>
                    {latestPriceDate ? ` (${latestPriceDate})` : ""}: {" "}
                    <span className="font-medium text-foreground">{formatIdr(latestPriceClose)}</span>
                  </span>
                ) : symbol ? (
                  "Enter a manual close price if automatic lookup is unavailable."
                ) : (
                  "Enter a symbol to fetch the latest close price automatically."
                )}
              </p>
            </div>
          </div>

          <div className="space-y-3 rounded-lg border border-dashed p-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="text-sm font-medium text-foreground">Percentage → Price</p>
                <p className="text-xs text-muted-foreground">
                  Enter a trailing stop percentage to see the corresponding price rounded down by the IDX tick rule.
                </p>
              </div>
              {tickSizeFromPercent ? (
                <p className="text-xs font-medium text-muted-foreground">Tick size: {tickSizeFromPercent.toLocaleString()} IDR</p>
              ) : null}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <label className="text-xs font-medium uppercase tracking-wide text-muted-foreground" htmlFor="stop-percent">
                  Trailing stop (%)
                </label>
                <Input
                  id="stop-percent"
                  inputMode="decimal"
                  type="number"
                  min="0"
                  step="0.1"
                  placeholder="e.g. 5"
                  value={percentInput}
                  onChange={(event) => setPercentInput(event.target.value)}
                />
              </div>
              <div className="space-y-2">
                <label className="text-xs font-medium uppercase tracking-wide text-muted-foreground" htmlFor="stop-price-result">
                  IDX adjusted price (IDR)
                </label>
                <Input
                  id="stop-price-result"
                  readOnly
                  tabIndex={-1}
                  value={
                    trailingPriceFromPercent !== null && Number.isFinite(trailingPriceFromPercent)
                      ? trailingPriceFromPercent.toString()
                      : ""
                  }
                  placeholder="Calculated from percentage"
                />
              </div>
            </div>
            <div className="rounded-md bg-muted/40 p-3 text-xs text-muted-foreground">
              {trailingPriceFromPercent !== null && Number.isFinite(trailingPriceFromPercent) && stopDistance !== null ? (
                <div className="space-y-1">
                  <p>
                    Trailing stop for <span className="font-medium text-foreground">{symbol || "your symbol"}</span> is
                    set at <span className="font-medium text-foreground">{formatIdr(trailingPriceFromPercent)}</span>.
                  </p>
                  <p>
                    Stop distance: {formatIdr(stopDistance)} ({
                      Number.isFinite(trailingPercent) ? trailingPercent.toFixed(2) : "0.00"
                    }
                    %).
                  </p>
                </div>
              ) : (
                <p>Provide the close price and a trailing stop percentage to generate an IDX-compliant stop price.</p>
              )}
            </div>
          </div>

          <div className="space-y-3 rounded-lg border border-dashed p-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="text-sm font-medium text-foreground">Price → Percentage</p>
                <p className="text-xs text-muted-foreground">
                  Convert an IDX-compliant stop price back into a trailing stop percentage for validation.
                </p>
              </div>
              {tickSizeFromPrice ? (
                <p className="text-xs font-medium text-muted-foreground">Tick size: {tickSizeFromPrice.toLocaleString()} IDR</p>
              ) : null}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <label className="text-xs font-medium uppercase tracking-wide text-muted-foreground" htmlFor="stop-price-input">
                  Trailing stop price (IDR)
                </label>
                <Input
                  id="stop-price-input"
                  inputMode="decimal"
                  type="number"
                  min="0"
                  step="0.01"
                  placeholder="e.g. 7500"
                  value={priceInput}
                  onChange={(event) => setPriceInput(event.target.value)}
                />
              </div>
              <div className="space-y-2">
                <label className="text-xs font-medium uppercase tracking-wide text-muted-foreground" htmlFor="stop-percent-result">
                  Equivalent TS (%)
                </label>
                <Input
                  id="stop-percent-result"
                  readOnly
                  tabIndex={-1}
                  value={
                    percentFromPrice !== null && Number.isFinite(percentFromPrice)
                      ? percentFromPrice.toFixed(2)
                      : ""
                  }
                  placeholder="Calculated from price"
                />
              </div>
            </div>
            <div className="rounded-md bg-muted/40 p-3 text-xs text-muted-foreground">
              {adjustedPriceFromInput !== null && Number.isFinite(closePrice) && closePrice > 0 ? (
                <div className="space-y-1">
                  <p>
                    Using the IDX tick rule, the provided price is interpreted as
                    <span className="font-medium text-foreground"> {formatIdr(adjustedPriceFromInput)}</span>.
                  </p>
                  {percentFromPrice !== null && Number.isFinite(percentFromPrice) ? (
                    <p>
                      This is a <span className="font-medium text-foreground">{percentFromPrice.toFixed(2)}%</span> trailing
                      stop from {formatIdr(closePrice)}.
                    </p>
                  ) : (
                    <p>Provide a latest close price to translate the stop level into a percentage.</p>
                  )}
                </div>
              ) : (
                <p>Enter a target stop price to evaluate its percentage distance from the latest close.</p>
              )}
            </div>
          </div>
        </CardContent>
      </Card>
      </div>
    </div>
  )
}
