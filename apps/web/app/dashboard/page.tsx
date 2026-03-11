"use client"

import { useEffect, useMemo, useRef, useState } from "react"

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
import { fetchPortfolios, type PositionRecord } from "@/lib/portfolio-client"
import { formatIdr } from "@/lib/currency"

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

const formatPercent = (value: number) => `${value > 0 ? "+" : ""}${value.toFixed(2)}%`

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

type AtrApiResponse = {
  asset_id: number
  symbol: string
  interval: "daily" | "weekly"
  period: number
  atr: number | string
  last_close: number | string | null
  last_date: string | null
  bar_count: number | string
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
  const [atrSymbol, setAtrSymbol] = useState("")
  const [atrPeriodInput, setAtrPeriodInput] = useState("14")
  const [atrInterval, setAtrInterval] = useState<"daily" | "weekly">("daily")
  const [atrLoading, setAtrLoading] = useState(false)
  const [atrError, setAtrError] = useState<string | null>(null)
  const [atrResult, setAtrResult] = useState<
    | {
        symbol: string
        interval: "daily" | "weekly"
        period: number
        atr: number
        lastClose: number | null
        lastDate: string | null
        barCount: number
      }
    | null
  >(null)
  const [entryPriceInput, setEntryPriceInput] = useState("")
  const [capitalInput, setCapitalInput] = useState("")
  const [lotSizeInput, setLotSizeInput] = useState("100")
  const [targetPriceInput, setTargetPriceInput] = useState("")
  const [targetPercentInput, setTargetPercentInput] = useState("")
  const [stopPriceInput, setStopPriceInput] = useState("")
  const [stopPercentInput, setStopPercentInput] = useState("")
  const [useSameFee, setUseSameFee] = useState(true)
  const [buyFeePercentInput, setBuyFeePercentInput] = useState("0")
  const [sellFeePercentInput, setSellFeePercentInput] = useState("0")
  const [portfolioSummary, setPortfolioSummary] = useState<{
    totalTrades: number
    uniqueAssets: number
    entryValue: number
    exitValue: number
    realizedPl: number
  } | null>(null)
  const [portfolioSummaryError, setPortfolioSummaryError] = useState<string | null>(null)
  const [positionValuePriceInput, setPositionValuePriceInput] = useState("")
  const [positionValueLotsInput, setPositionValueLotsInput] = useState("")
  const [averageTransactions, setAverageTransactions] = useState([
    { id: 1, side: "buy" as const, price: "", lots: "" },
    { id: 2, side: "buy" as const, price: "", lots: "" },
    { id: 3, side: "buy" as const, price: "", lots: "" },
    { id: 4, side: "buy" as const, price: "", lots: "" },
    { id: 5, side: "buy" as const, price: "", lots: "" },
  ])
  const [positionCompareSymbol, setPositionCompareSymbol] = useState("")
  const [positionCompareLoading, setPositionCompareLoading] = useState(false)
  const [positionCompareError, setPositionCompareError] = useState<string | null>(null)
  const [positionCompareClose, setPositionCompareClose] = useState<number | null>(null)
  const [positionCompareDate, setPositionCompareDate] = useState<string | null>(null)

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
    if (!accessToken) {
      setPortfolioSummary(null)
      setPortfolioSummaryError("Sign in to view portfolio activity.")
      return
    }

    let isCancelled = false

    const loadPortfolioSummary = async () => {
      try {
        const portfolios = await fetchPortfolios(accessToken, { includePositions: true })
        const first = portfolios[0]

        if (!first) {
          if (!isCancelled) {
            setPortfolioSummary(null)
            setPortfolioSummaryError("No portfolio data available.")
          }
          return
        }

        const positions: PositionRecord[] = first.positions ?? []
        const assetSummaries = first.asset_summaries ?? []
        const entryValue = assetSummaries.reduce((sum, item) => sum + (item.entry.value ?? 0), 0)
        const exitValue = assetSummaries.reduce((sum, item) => sum + (item.exit.value ?? 0), 0)
        const realizedPl = exitValue - entryValue

        if (!isCancelled) {
          setPortfolioSummary({
            totalTrades: positions.length,
            uniqueAssets: assetSummaries.length,
            entryValue,
            exitValue,
            realizedPl,
          })
          setPortfolioSummaryError(null)
        }
      } catch (cause) {
        if (isCancelled) {
          return
        }

        const message =
          cause instanceof Error && cause.message
            ? cause.message
            : "Unable to load portfolio activity."
        setPortfolioSummary(null)
        setPortfolioSummaryError(message)
      }
    }

    loadPortfolioSummary()

    return () => {
      isCancelled = true
    }
  }, [accessToken])

  const highlights = useMemo(
    () => [
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
        title: "Portfolio Status",
        value: portfolioSummary
          ? `${portfolioSummary.uniqueAssets} assets · ${portfolioSummary.totalTrades} trades`
          : portfolioSummaryError ?? "Loading portfolio activity...",
        description: portfolioSummary
          ? `Entry: ${formatIdr(portfolioSummary.entryValue)} · Exit: ${formatIdr(portfolioSummary.exitValue)} · P/L: ${formatIdr(portfolioSummary.realizedPl)}`
          : portfolioSummaryError ?? "Loading portfolio activity...",
      },
    ],
    [portfolioSummary, portfolioSummaryError],
  )

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
        const response = await fetch(
          buildApiUrl(`/v1/assets/${encodeURIComponent(debouncedSymbol)}/latest-price`),
          {
            method: "GET",
            headers: {
              Accept: "application/json",
              Authorization: `Bearer ${accessToken}`,
            },
            signal: controller.signal,
          },
        )

        const payload = await parseJson<ApiResponse<LatestPriceRecord>>(response)

        if (response.status === 404) {
          if (isCancelled) {
            return
          }

          setLatestPriceError(`No latest close price found for ${debouncedSymbol}.`)
          setLatestPriceClose(null)
          setLatestPriceDate(null)
          setLastFetchedSymbol(debouncedSymbol)
          return
        }

        if (!response.ok) {
          const message =
            (payload && "message" in payload && typeof payload.message === "string" && payload.message) ||
            "Unable to fetch the latest close price."

          throw new Error(message)
        }

        if (!payload || payload.status !== "success" || !payload.data) {
          throw new Error("Unexpected response from the asset API.")
        }

        if (isCancelled) {
          return
        }

        const match = payload.data

        const close =
          typeof match.close === "number" ? match.close : Number.parseFloat(match.close ? String(match.close) : "")

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

  const entryPrice = parseNumericInput(entryPriceInput)
  const capitalAllocation = parseNumericInput(capitalInput)
  const lotSize = parseNumericInput(lotSizeInput)
  const targetPrice = parseNumericInput(targetPriceInput)
  const targetPercent = parseNumericInput(targetPercentInput)
  const stopPrice = parseNumericInput(stopPriceInput)
  const stopPercent = parseNumericInput(stopPercentInput)
  const buyFeePercent = parseNumericInput(buyFeePercentInput)
  const sellFeePercent = useSameFee ? buyFeePercent : parseNumericInput(sellFeePercentInput)

  const normalizedLotSize = Number.isFinite(lotSize) && lotSize > 0 ? lotSize : null
  const buyFeeRate = Number.isFinite(buyFeePercent) && buyFeePercent > 0 ? buyFeePercent / 100 : 0
  const sellFeeRate = Number.isFinite(sellFeePercent) && sellFeePercent > 0 ? sellFeePercent / 100 : 0
  const targetPriceFromPercent =
    Number.isFinite(entryPrice) && entryPrice > 0 && Number.isFinite(targetPercent)
      ? Number((entryPrice * (1 + targetPercent / 100)).toFixed(2))
      : null
  const stopPriceFromPercent =
    Number.isFinite(entryPrice) && entryPrice > 0 && Number.isFinite(stopPercent)
      ? Number((entryPrice * (1 - stopPercent / 100)).toFixed(2))
      : null
  const targetPriceDisabled = targetPercentInput.trim().length > 0
  const targetPercentDisabled = targetPriceInput.trim().length > 0
  const stopPriceDisabled = stopPercentInput.trim().length > 0
  const stopPercentDisabled = stopPriceInput.trim().length > 0
  const targetPriceInputValue = targetPriceDisabled
    ? targetPriceFromPercent !== null
      ? targetPriceFromPercent.toString()
      : ""
    : targetPriceInput
  const stopPriceInputValue = stopPriceDisabled
    ? stopPriceFromPercent !== null
      ? stopPriceFromPercent.toString()
      : ""
    : stopPriceInput
  const maxLots =
    Number.isFinite(entryPrice) && entryPrice > 0 &&
    Number.isFinite(capitalAllocation) && capitalAllocation > 0 &&
    normalizedLotSize !== null
      ? Math.floor(capitalAllocation / (entryPrice * normalizedLotSize))
      : null

  const totalShares = maxLots !== null && normalizedLotSize !== null ? maxLots * normalizedLotSize : null
  const buyValue = totalShares !== null && Number.isFinite(entryPrice) && entryPrice > 0 ? entryPrice * totalShares : null
  const buyFee = buyValue !== null ? buyValue * buyFeeRate : null
  const totalCostWithFee = buyValue !== null ? buyValue + (buyFee ?? 0) : null
  const effectiveTargetPrice = Number.isFinite(targetPrice) ? targetPrice : targetPriceFromPercent
  const effectiveStopPrice = Number.isFinite(stopPrice) ? stopPrice : stopPriceFromPercent

  const profitLossFromTarget =
    totalShares !== null && Number.isFinite(entryPrice) && entryPrice > 0 && Number.isFinite(effectiveTargetPrice)
      ? (() => {
          const gross = (effectiveTargetPrice - entryPrice) * totalShares
          const sellValue = effectiveTargetPrice * totalShares
          const totalFees = buyFeeRate > 0 || sellFeeRate > 0 ? (buyValue ?? 0) * buyFeeRate + sellValue * sellFeeRate : 0
          const net = gross - totalFees
          const percent = totalCostWithFee && totalCostWithFee !== 0 ? (net / totalCostWithFee) * 100 : null

          return { gross, net, percent }
        })()
      : null

  const profitLossFromStop =
    totalShares !== null && Number.isFinite(entryPrice) && entryPrice > 0 && Number.isFinite(effectiveStopPrice)
      ? (() => {
          const gross = (effectiveStopPrice - entryPrice) * totalShares
          const sellValue = effectiveStopPrice * totalShares
          const totalFees = buyFeeRate > 0 || sellFeeRate > 0 ? (buyValue ?? 0) * buyFeeRate + sellValue * sellFeeRate : 0
          const net = gross - totalFees
          const percent = totalCostWithFee && totalCostWithFee !== 0 ? (net / totalCostWithFee) * 100 : null

          return { gross, net, percent }
        })()
      : null

  const handleAtrCalculation = async () => {
    const trimmedSymbol = atrSymbol.trim().toUpperCase()

    if (!trimmedSymbol) {
      setAtrError("Enter a symbol to compute ATR.")
      setAtrResult(null)
      return
    }

    const parsedPeriod = Number.parseInt(atrPeriodInput, 10)
    if (!Number.isFinite(parsedPeriod) || parsedPeriod <= 0) {
      setAtrError("Provide a valid ATR period greater than zero.")
      setAtrResult(null)
      return
    }

    if (!accessToken) {
      setAtrError("Sign in to compute ATR values.")
      setAtrResult(null)
      return
    }

    setAtrLoading(true)
    setAtrError(null)
    setAtrResult(null)

    const params = new URLSearchParams({
      interval: atrInterval,
      period: parsedPeriod.toString(),
    })

    try {
      const response = await fetch(
        buildApiUrl(`/v1/assets/${encodeURIComponent(trimmedSymbol)}/atr?${params.toString()}`),
        {
          method: "GET",
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${accessToken}`,
          },
        },
      )

      const payload = await parseJson<ApiResponse<AtrApiResponse>>(response)

      if (response.status === 404) {
        setAtrError(`No price history found for ${trimmedSymbol}.`)
        return
      }

      if (!response.ok) {
        const message =
          (payload && "message" in payload && typeof payload.message === "string" && payload.message) ||
          "Unable to compute ATR."

        throw new Error(message)
      }

      if (!payload || payload.status !== "success" || !payload.data) {
        throw new Error("Unexpected response from the ATR API.")
      }

      const data = payload.data

      const atrValue =
        typeof data.atr === "number" ? data.atr : Number.parseFloat(data.atr ? String(data.atr) : "")

      if (!Number.isFinite(atrValue)) {
        throw new Error("ATR value was not numeric.")
      }

      const parsedPeriodFromResponse =
        typeof data.period === "number"
          ? data.period
          : Number.parseInt(data.period ? String(data.period) : "", 10)

      const normalizedPeriod =
        Number.isFinite(parsedPeriodFromResponse) && parsedPeriodFromResponse > 0
          ? parsedPeriodFromResponse
          : parsedPeriod

      const parsedBarCount =
        typeof data.bar_count === "number"
          ? data.bar_count
          : Number.parseInt(data.bar_count ? String(data.bar_count) : "", 10)

      const normalizedBarCount =
        Number.isFinite(parsedBarCount) && parsedBarCount > 0 ? parsedBarCount : normalizedPeriod

      const lastCloseValue =
        typeof data.last_close === "number"
          ? data.last_close
          : data.last_close !== null && data.last_close !== undefined
            ? Number.parseFloat(String(data.last_close))
            : null

      const normalizedLastClose =
        typeof lastCloseValue === "number" && Number.isFinite(lastCloseValue) ? lastCloseValue : null

      setAtrResult({
        symbol: typeof data.symbol === "string" && data.symbol ? data.symbol : trimmedSymbol,
        interval: data.interval === "weekly" ? "weekly" : "daily",
        period: normalizedPeriod,
        atr: atrValue,
        lastClose: normalizedLastClose,
        lastDate: data.last_date ?? null,
        barCount: normalizedBarCount,
      })
      setAtrSymbol(trimmedSymbol)
    } catch (error) {
      const message = error instanceof Error ? error.message : "Unable to compute ATR."
      setAtrError(message)
    } finally {
      setAtrLoading(false)
    }
  }

  const atrPercent =
    atrResult && atrResult.lastClose && atrResult.lastClose > 0
      ? (atrResult.atr / atrResult.lastClose) * 100
      : null

  const handlePositionCompareLookup = async () => {
    const trimmedSymbol = positionCompareSymbol.trim().toUpperCase()

    if (!trimmedSymbol) {
      setPositionCompareError("Enter an asset ticker to fetch the latest close.")
      setPositionCompareClose(null)
      setPositionCompareDate(null)
      return
    }

    if (!accessToken) {
      setPositionCompareError("Sign in to fetch the latest close price.")
      setPositionCompareClose(null)
      setPositionCompareDate(null)
      return
    }

    setPositionCompareLoading(true)
    setPositionCompareError(null)

    try {
      const response = await fetch(buildApiUrl(`/v1/assets/${encodeURIComponent(trimmedSymbol)}/latest-price`), {
        method: "GET",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${accessToken}`,
        },
      })

      const payload = await parseJson<ApiResponse<LatestPriceRecord>>(response)

      if (response.status === 404) {
        setPositionCompareError(`No latest close price found for ${trimmedSymbol}.`)
        setPositionCompareClose(null)
        setPositionCompareDate(null)
        return
      }

      if (!response.ok) {
        const message =
          (payload && "message" in payload && typeof payload.message === "string" && payload.message) ||
          "Unable to fetch the latest close price."

        throw new Error(message)
      }

      if (!payload || payload.status !== "success" || !payload.data) {
        throw new Error("Unexpected response from the asset API.")
      }

      const close =
        typeof payload.data.close === "number"
          ? payload.data.close
          : Number.parseFloat(payload.data.close ? String(payload.data.close) : "")

      if (!Number.isFinite(close) || close <= 0) {
        setPositionCompareError(`Latest close price for ${trimmedSymbol} is unavailable.`)
        setPositionCompareClose(null)
        setPositionCompareDate(payload.data.date ?? null)
        return
      }

      setPositionCompareClose(close)
      setPositionCompareDate(payload.data.date ?? null)
      setPositionCompareError(null)
    } catch (error) {
      const message = error instanceof Error ? error.message : "Unable to fetch the latest close price."
      setPositionCompareError(message)
      setPositionCompareClose(null)
      setPositionCompareDate(null)
    } finally {
      setPositionCompareLoading(false)
    }
  }

  const atrSummarySymbol = atrResult?.symbol ?? atrSymbol.trim().toUpperCase()
  const positionValuePrice = parseNumericInput(positionValuePriceInput)
  const positionValueLots = parseNumericInput(positionValueLotsInput)
  const positionValueSharesPerLot = normalizedLotSize ?? 100
  const positionValueAmount =
    Number.isFinite(positionValuePrice) && positionValuePrice > 0 && Number.isFinite(positionValueLots) && positionValueLots > 0
      ? positionValuePrice * positionValueLots * positionValueSharesPerLot
      : null

  const averagePriceBreakdown = useMemo(() => {
    const sharesPerLot = normalizedLotSize ?? 100
    let openShares = 0
    let openCost = 0
    let buyValue = 0
    let sellValue = 0
    let realizedPl = 0
    let ignoredSellShares = 0

    averageTransactions.forEach((transaction) => {
      const price = parseNumericInput(transaction.price)
      const lots = parseNumericInput(transaction.lots)

      if (!Number.isFinite(price) || price <= 0 || !Number.isFinite(lots) || lots <= 0) {
        return
      }

      const normalizedLots = Math.floor(lots)
      if (normalizedLots <= 0) {
        return
      }

      const shares = normalizedLots * sharesPerLot

      if (transaction.side === "buy") {
        openShares += shares
        openCost += shares * price
        buyValue += shares * price
        return
      }

      if (openShares <= 0) {
        ignoredSellShares += shares
        return
      }

      const sharesToSell = Math.min(shares, openShares)
      const averageOpenCost = openCost / openShares

      realizedPl += sharesToSell * (price - averageOpenCost)
      sellValue += sharesToSell * price
      openShares -= sharesToSell
      openCost -= sharesToSell * averageOpenCost

      if (shares > sharesToSell) {
        ignoredSellShares += shares - sharesToSell
      }

      if (openShares === 0) {
        openCost = 0
      }
    })

    if (buyValue <= 0 && sellValue <= 0 && openShares <= 0) {
      return null
    }

    const openLots = sharesPerLot > 0 ? openShares / sharesPerLot : 0

    return {
      sharesPerLot,
      openShares,
      openLots,
      openCost,
      buyValue,
      sellValue,
      realizedPl,
      ignoredSellShares,
      averagePricePerShare: openShares > 0 ? openCost / openShares : null,
      averageCostPerLot: openLots > 0 ? openCost / openLots : null,
    }
  }, [averageTransactions, normalizedLotSize])

  const unrealizedPlValue =
    averagePriceBreakdown &&
    averagePriceBreakdown.openShares > 0 &&
    averagePriceBreakdown.averagePricePerShare !== null &&
    positionCompareClose !== null
      ? (positionCompareClose - averagePriceBreakdown.averagePricePerShare) * averagePriceBreakdown.openShares
      : null

  const unrealizedPlPercent =
    unrealizedPlValue !== null && averagePriceBreakdown && averagePriceBreakdown.openCost > 0
      ? (unrealizedPlValue / averagePriceBreakdown.openCost) * 100
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
            <CardTitle>Position sizing &amp; P/L</CardTitle>
            <CardDescription>
              Quickly work out lots, share count, and potential profit or loss based on allocation and fees.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="space-y-3 rounded-lg border border-dashed p-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground" htmlFor="entry-price">
                    Entry price per share (IDR)
                  </label>
                  <Input
                    id="entry-price"
                    inputMode="decimal"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="e.g. 5125"
                    value={entryPriceInput}
                    onChange={(event) => setEntryPriceInput(event.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground" htmlFor="capital">
                    Capital allocation (IDR)
                  </label>
                  <Input
                    id="capital"
                    inputMode="decimal"
                    type="number"
                    min="0"
                    step="1000"
                    placeholder="Budget for this trade"
                    value={capitalInput}
                    onChange={(event) => setCapitalInput(event.target.value)}
                  />
                </div>
              </div>
              <div className="grid gap-4 sm:grid-cols-3">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground" htmlFor="lot-size">
                    Unit per lot
                  </label>
                  <Input
                    id="lot-size"
                    inputMode="numeric"
                    type="number"
                    min="1"
                    step="1"
                    value={lotSizeInput}
                    onChange={(event) => setLotSizeInput(event.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">IDX uses 100 shares per lot by default.</p>
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground" htmlFor="target-price">
                    Target price (optional)
                  </label>
                  <Input
                    id="target-price"
                    inputMode="decimal"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="Take-profit price"
                    disabled={targetPriceDisabled}
                    value={targetPriceInputValue}
                    onChange={(event) => setTargetPriceInput(event.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground" htmlFor="target-percent">
                    Target move (%)
                  </label>
                  <Input
                    id="target-percent"
                    inputMode="decimal"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="e.g. 8"
                    disabled={targetPercentDisabled}
                    value={targetPercentInput}
                    onChange={(event) => setTargetPercentInput(event.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">Fill either a price or percentage to auto-calc the other.</p>
                </div>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground" htmlFor="stop-price">
                    Stop-loss price (optional)
                  </label>
                  <Input
                    id="stop-price"
                    inputMode="decimal"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="Protective stop"
                    disabled={stopPriceDisabled}
                    value={stopPriceInputValue}
                    onChange={(event) => setStopPriceInput(event.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground" htmlFor="stop-percent">
                    Stop distance (%)
                  </label>
                  <Input
                    id="stop-percent"
                    inputMode="decimal"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="e.g. 5"
                    disabled={stopPercentDisabled}
                    value={stopPercentInput}
                    onChange={(event) => setStopPercentInput(event.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">Entering a percentage locks the price field and vice versa.</p>
                </div>
              </div>
              <div className="space-y-4">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="text-sm font-medium text-foreground">Fees</p>
                    <p className="text-xs text-muted-foreground">
                      Use a single rate for both legs or specify separate buy/sell fees.
                    </p>
                  </div>
                  <label className="flex items-center gap-2 text-sm font-medium text-foreground" htmlFor="use-same-fee">
                    <input
                      id="use-same-fee"
                      type="checkbox"
                      className="h-4 w-4 rounded border-border text-primary focus-visible:outline-none"
                      checked={useSameFee}
                      onChange={(event) => {
                        setUseSameFee(event.target.checked)
                        if (event.target.checked) {
                          setSellFeePercentInput(buyFeePercentInput)
                        }
                      }}
                    />
                    Use same fee for buy & sell
                  </label>
                </div>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 sm:items-end">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-foreground" htmlFor="buy-fee-percent">
                      {useSameFee ? "Fee (% per side)" : "Buy fee (%)"}
                    </label>
                    <Input
                      id="buy-fee-percent"
                      inputMode="decimal"
                      type="number"
                      min="0"
                      step="0.01"
                      value={buyFeePercentInput}
                      onChange={(event) => {
                        setBuyFeePercentInput(event.target.value)
                        if (useSameFee) {
                          setSellFeePercentInput(event.target.value)
                        }
                      }}
                    />
                  </div>
                  {!useSameFee ? (
                    <div className="space-y-2">
                      <label className="text-sm font-medium text-foreground" htmlFor="sell-fee-percent">
                        Sell fee (%)
                      </label>
                      <Input
                        id="sell-fee-percent"
                        inputMode="decimal"
                        type="number"
                        min="0"
                        step="0.01"
                        value={sellFeePercentInput}
                        onChange={(event) => setSellFeePercentInput(event.target.value)}
                      />
                    </div>
                  ) : null}
                  <p className="text-xs text-muted-foreground lg:col-span-1">
                    Fees are applied on both the buy and sell legs when estimating net P/L.
                  </p>
                </div>
              </div>
            </div>

            <div className="space-y-4 rounded-lg border border-dashed p-4 text-sm">
              {maxLots !== null && normalizedLotSize !== null ? (
                maxLots > 0 ? (
                  <>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Lots to trade</p>
                        <p className="text-lg font-semibold text-foreground">{maxLots.toLocaleString()}</p>
                        <p className="text-xs text-muted-foreground">{totalShares?.toLocaleString()} shares in total.</p>
                      </div>
                      <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Cash required</p>
                        <p className="text-lg font-semibold text-foreground">
                          {totalCostWithFee !== null ? formatIdr(totalCostWithFee) : buyValue !== null ? formatIdr(buyValue) : "—"}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {buyFee && buyFee > 0
                            ? `Includes approximately ${formatIdr(buyFee)} in fees on entry.`
                            : "No fees applied to the buy leg."}
                        </p>
                      </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                      <div className="rounded-md bg-muted/40 p-3">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Take profit</p>
                        {profitLossFromTarget ? (
                          <div className="space-y-1">
                            <p>
                              Gross P/L at {formatIdr(effectiveTargetPrice ?? 0)}: {formatIdr(profitLossFromTarget.gross)}
                            </p>
                            <p>
                              Net after fees: <span className="font-medium text-foreground">{formatIdr(profitLossFromTarget.net)}</span>
                            </p>
                            {profitLossFromTarget.percent !== null ? (
                              <p className="text-xs text-muted-foreground">
                                Net return vs. cost: <span className="font-medium text-foreground">{formatPercent(profitLossFromTarget.percent)}</span>
                              </p>
                            ) : null}
                          </div>
                        ) : (
                          <p className="text-xs text-muted-foreground">Add a target price or percentage to estimate potential profit.</p>
                        )}
                      </div>
                      <div className="rounded-md bg-muted/40 p-3">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Stop-loss</p>
                        {profitLossFromStop ? (
                          <div className="space-y-1">
                            <p>
                              Gross P/L at {formatIdr(effectiveStopPrice ?? 0)}: {formatIdr(profitLossFromStop.gross)}
                            </p>
                            <p>
                              Net after fees: <span className="font-medium text-foreground">{formatIdr(profitLossFromStop.net)}</span>
                            </p>
                            {profitLossFromStop.percent !== null ? (
                              <p className="text-xs text-muted-foreground">
                                Net return vs. cost: <span className="font-medium text-foreground">{formatPercent(profitLossFromStop.percent)}</span>
                              </p>
                            ) : null}
                          </div>
                        ) : (
                          <p className="text-xs text-muted-foreground">Add a stop price or percentage to preview downside risk.</p>
                        )}
                      </div>
                    </div>
                  </>
                ) : (
                  <p>You need more capital to purchase at least one lot with the current inputs.</p>
                )
              ) : (
                <p>Provide an entry price, capital, and lot size to determine how many lots you can place.</p>
              )}
            </div>
          </CardContent>
        </Card>

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
                  <p className="mb-3 text-sm font-medium text-foreground">Percentage → Price</p>
                  <p className="text-xs text-muted-foreground">
                    Enter a trailing stop percentage to see the corresponding price rounded down by the IDX tick rule.
                  </p>
                </div>
                {tickSizeFromPercent ? (
                  <p className="text-xs font-medium text-muted-foreground">
                    Tick size: {tickSizeFromPercent.toLocaleString()} IDR
                  </p>
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
                    Price (IDR)
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
                      Trailing stop for <span className="font-medium text-foreground">{symbol || "your symbol"}</span> is set at
                      <span className="font-medium text-foreground"> {formatIdr(trailingPriceFromPercent)}</span>.
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
                    TS price (IDR)
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

        <div className="flex flex-col gap-4">
          <Card className="flex-1">
            <CardHeader>
              <CardTitle>ATR calculator</CardTitle>
              <CardDescription>
                Compute Average True Range (ATR) from AssetMetrics using daily or weekly price bars.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="space-y-3 rounded-lg border border-dashed p-4">
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-foreground" htmlFor="atr-symbol">
                      Symbol
                    </label>
                    <Input
                      id="atr-symbol"
                      placeholder="e.g. BBCA"
                      value={atrSymbol}
                      onChange={(event) => {
                        setAtrSymbol(event.target.value.toUpperCase())
                        setAtrError(null)
                      }}
                      autoComplete="off"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-foreground" htmlFor="atr-period">
                      Lookback period
                    </label>
                    <Input
                      id="atr-period"
                      inputMode="numeric"
                      type="number"
                      min="1"
                      step="1"
                      value={atrPeriodInput}
                      onChange={(event) => {
                        setAtrPeriodInput(event.target.value)
                        setAtrError(null)
                      }}
                    />
                  </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-foreground" htmlFor="atr-interval">
                      Interval
                    </label>
                    <select
                      id="atr-interval"
                      className="flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"
                      value={atrInterval}
                      onChange={(event) => {
                        setAtrInterval(event.target.value === "weekly" ? "weekly" : "daily")
                        setAtrError(null)
                      }}
                    >
                      <option value="daily">Daily</option>
                      <option value="weekly">Weekly</option>
                    </select>
                  </div>
                  <Button type="button" onClick={handleAtrCalculation} disabled={atrLoading || !atrSymbol.trim()}>
                    {atrLoading ? "Computing..." : "Compute ATR"}
                  </Button>
                </div>
                <div className="min-h-[3.25rem] rounded-md bg-muted/40 p-3 text-xs text-muted-foreground">
                  {atrLoading ? (
                    "Computing ATR with the AssetMetrics service..."
                  ) : atrError ? (
                    <span className="text-destructive">{atrError}</span>
                  ) : atrResult ? (
                    <span>
                      ATR for <span className="font-medium text-foreground">{atrSummarySymbol}</span> ({" "}
                      {atrResult.interval === "weekly" ? "weekly" : "daily"} {atrResult.period}) is {" "}
                      <span className="font-medium text-foreground">{formatIdr(atrResult.atr)}</span>.
                    </span>
                  ) : (
                    "Enter a symbol, choose an interval, and click compute to retrieve ATR from AssetMetrics."
                  )}
                </div>
              </div>

              {atrResult ? (
                <div className="space-y-4 rounded-lg border border-dashed p-4 text-sm">
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        ATR ({atrResult.interval === "weekly" ? "Weekly" : "Daily"})
                      </p>
                      <p className="text-lg font-semibold text-foreground">{formatIdr(atrResult.atr)}</p>
                      {atrPercent !== null ? (
                        <p className="text-xs text-muted-foreground">{atrPercent.toFixed(2)}% of the latest close.</p>
                      ) : null}
                    </div>
                    <div>
                      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Latest close</p>
                      <p className="text-lg font-semibold text-foreground">
                        {atrResult.lastClose !== null ? formatIdr(atrResult.lastClose) : "—"}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {atrResult.lastDate ? `As of ${atrResult.lastDate}` : "Close date unavailable."}
                      </p>
                    </div>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Computed over {atrResult.barCount.toLocaleString()} {atrResult.interval} bar
                    {atrResult.barCount === 1 ? "" : "s"} using a {atrResult.period}-period lookback.
                  </p>
                </div>
              ) : null}
            </CardContent>
          </Card>

          <Card className="flex-1">
            <CardHeader>
              <CardTitle>Position value calculator</CardTitle>
              <CardDescription>
                Estimate the cash value of a position from the current price and lot quantity.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-3 rounded-lg border border-dashed p-4">
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-foreground" htmlFor="position-value-price">
                      Current price (IDR)
                    </label>
                    <Input
                      id="position-value-price"
                      inputMode="decimal"
                      type="number"
                      min="0"
                      step="0.01"
                      placeholder="e.g. 5250"
                      value={positionValuePriceInput}
                      onChange={(event) => setPositionValuePriceInput(event.target.value)}
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-foreground" htmlFor="position-value-lots">
                      Lot quantity
                    </label>
                    <Input
                      id="position-value-lots"
                      inputMode="numeric"
                      type="number"
                      min="0"
                      step="1"
                      placeholder="e.g. 4"
                      value={positionValueLotsInput}
                      onChange={(event) => setPositionValueLotsInput(event.target.value)}
                    />
                  </div>
                </div>
                <div className="rounded-md bg-muted/40 p-3 text-xs text-muted-foreground">
                  {positionValueAmount !== null ? (
                    <div className="space-y-1">
                      <p>
                        Position value: <span className="font-medium text-foreground">{formatIdr(positionValueAmount)}</span>
                      </p>
                      <p>
                        {positionValueLots.toLocaleString()} lot × {positionValueSharesPerLot.toLocaleString()} shares × {formatIdr(positionValuePrice)}.
                      </p>
                    </div>
                  ) : (
                    <p>Enter a current price and lot quantity to calculate the position value.</p>
                  )}
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="flex-1">
            <CardHeader>
              <CardTitle>Position average &amp; P/L tracker</CardTitle>
              <CardDescription>
                Add buy/sell transactions, then compare your remaining position with the latest close by ticker.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-3 rounded-lg border border-dashed p-4">
                <div className="flex items-center justify-between gap-3">
                  <p className="text-sm font-medium text-foreground">Transactions</p>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setAverageTransactions((current) => {
                        const nextId = current.length > 0 ? Math.max(...current.map((item) => item.id)) + 1 : 1
                        return [...current, { id: nextId, side: "buy", price: "", lots: "" }]
                      })
                    }}
                  >
                    Add transaction
                  </Button>
                </div>

                {averageTransactions.map((entry, index) => (
                  <div key={entry.id} className="space-y-3 rounded-md border p-3">
                    <div className="flex items-center justify-between">
                      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Transaction #{index + 1}</p>
                      {averageTransactions.length > 1 ? (
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          onClick={() => {
                            setAverageTransactions((current) => current.filter((item) => item.id !== entry.id))
                          }}
                        >
                          Remove
                        </Button>
                      ) : null}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                      <div className="space-y-2">
                        <label className="text-sm font-medium text-foreground" htmlFor={`avg-side-${entry.id}`}>
                          Side
                        </label>
                        <select
                          id={`avg-side-${entry.id}`}
                          className="flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                          value={entry.side}
                          onChange={(event) => {
                            setAverageTransactions((current) =>
                              current.map((item) =>
                                item.id === entry.id
                                  ? { ...item, side: event.target.value === "sell" ? "sell" : "buy" }
                                  : item,
                              ),
                            )
                          }}
                        >
                          <option value="buy">Buy</option>
                          <option value="sell">Sell</option>
                        </select>
                      </div>
                      <div className="space-y-2">
                        <label className="text-sm font-medium text-foreground" htmlFor={`avg-price-${entry.id}`}>
                          Price (IDR)
                        </label>
                        <Input
                          id={`avg-price-${entry.id}`}
                          inputMode="decimal"
                          type="number"
                          min="0"
                          step="0.01"
                          placeholder="e.g. 5250"
                          value={entry.price}
                          onChange={(event) => {
                            setAverageTransactions((current) =>
                              current.map((item) => (item.id === entry.id ? { ...item, price: event.target.value } : item)),
                            )
                          }}
                        />
                      </div>
                      <div className="space-y-2">
                        <label className="text-sm font-medium text-foreground" htmlFor={`avg-lots-${entry.id}`}>
                          Lots
                        </label>
                        <Input
                          id={`avg-lots-${entry.id}`}
                          inputMode="numeric"
                          type="number"
                          min="0"
                          step="1"
                          placeholder="e.g. 2"
                          value={entry.lots}
                          onChange={(event) => {
                            setAverageTransactions((current) =>
                              current.map((item) => (item.id === entry.id ? { ...item, lots: event.target.value } : item)),
                            )
                          }}
                        />
                      </div>
                    </div>
                  </div>
                ))}

                <div className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-foreground" htmlFor="position-compare-symbol">
                      Compare with latest close (ticker)
                    </label>
                    <Input
                      id="position-compare-symbol"
                      placeholder="e.g. BBCA"
                      value={positionCompareSymbol}
                      onChange={(event) => {
                        setPositionCompareSymbol(event.target.value.toUpperCase())
                        setPositionCompareError(null)
                      }}
                      autoComplete="off"
                    />
                  </div>
                  <Button type="button" variant="outline" onClick={handlePositionCompareLookup} disabled={positionCompareLoading}>
                    {positionCompareLoading ? "Checking..." : "Get latest close"}
                  </Button>
                </div>

                <div className="rounded-md bg-muted/40 p-3 text-xs text-muted-foreground">
                  {averagePriceBreakdown ? (
                    <div className="space-y-1">
                      <p>
                        Open position: <span className="font-medium text-foreground">{averagePriceBreakdown.openLots.toLocaleString()} lots</span> ({averagePriceBreakdown.openShares.toLocaleString()} shares)
                      </p>
                      <p>
                        Remaining cost basis: <span className="font-medium text-foreground">{formatIdr(averagePriceBreakdown.openCost)}</span>
                      </p>
                      <p>
                        Average open price per share: <span className="font-medium text-foreground">{averagePriceBreakdown.averagePricePerShare !== null ? formatIdr(averagePriceBreakdown.averagePricePerShare) : "—"}</span>
                      </p>
                      {averagePriceBreakdown.averageCostPerLot !== null ? (
                        <p>Average open cost per lot: {formatIdr(averagePriceBreakdown.averageCostPerLot)}</p>
                      ) : null}
                      <p>
                        Realized P/L from sells: <span className={`font-medium ${averagePriceBreakdown.realizedPl >= 0 ? "text-emerald-600" : "text-destructive"}`}>{formatIdr(averagePriceBreakdown.realizedPl)}</span>
                      </p>
                      {averagePriceBreakdown.ignoredSellShares > 0 ? (
                        <p>
                          Ignored sell quantity: {averagePriceBreakdown.ignoredSellShares.toLocaleString()} shares (selling more than current position).
                        </p>
                      ) : null}
                      {positionCompareError ? <p className="text-destructive">{positionCompareError}</p> : null}
                      {positionCompareClose !== null && averagePriceBreakdown.openShares > 0 ? (
                        <>
                          <p>
                            Latest close: <span className="font-medium text-foreground">{formatIdr(positionCompareClose)}</span>
                            {positionCompareDate ? ` (as of ${positionCompareDate})` : ""}
                          </p>
                          <p>
                            Unrealized P/L: <span className={`font-medium ${unrealizedPlValue !== null && unrealizedPlValue >= 0 ? "text-emerald-600" : "text-destructive"}`}>{unrealizedPlValue !== null ? formatIdr(unrealizedPlValue) : "—"}</span>
                            {unrealizedPlPercent !== null ? ` (${formatPercent(unrealizedPlPercent)})` : ""}
                          </p>
                        </>
                      ) : null}
                    </div>
                  ) : (
                    <p>Add at least one valid buy/sell transaction to track average price, cost basis, and P/L.</p>
                  )}
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
