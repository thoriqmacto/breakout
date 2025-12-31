"use client"

import Link from "next/link"
import { useParams, useRouter } from "next/navigation"
import { useEffect, useMemo, useState } from "react"
import { ArrowLeft, ExternalLink, Info } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import {
  normalizeMetrics,
  type AssetMetricApiRow,
  type AssetMetricRow,
} from "@/lib/asset-metrics"

type LatestPrice = {
  id: number
  asset_id: number
  date: string | null
  open: number | null
  high: number | null
  low: number | null
  close: number | string | null
  volume: number | null
}

type AssetDetail = {
  id: number
  symbol: string
  name: string
  lot_size: number | null
  tick_size: number | null
  latest_price?: LatestPrice | null
}

type AssetShowResponse = AssetDetail

type MetricResponse = {
  metric?: AssetMetricApiRow
}

const priceFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
})

const integerFormatter = new Intl.NumberFormat("en-US", {
  maximumFractionDigits: 0,
})

const ratioFormatter = new Intl.NumberFormat("en-US", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const formatNumber = (
  value: number | null | undefined,
  formatter: Intl.NumberFormat,
  options: { suffix?: string } = {},
) => {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return "—"
  }

  return `${formatter.format(value)}${options.suffix ?? ""}`
}

export default function AssetDetailPage() {
  const { accessToken } = useAuth()
  const params = useParams()
  const router = useRouter()
  const assetIdParam = params?.assetId
  const assetId = useMemo(() => {
    if (!assetIdParam) {
      return null
    }
    const parsed = Number.parseInt(Array.isArray(assetIdParam) ? assetIdParam[0] : assetIdParam, 10)
    return Number.isNaN(parsed) ? null : parsed
  }, [assetIdParam])

  const [asset, setAsset] = useState<AssetDetail | null>(null)
  const [assetLoading, setAssetLoading] = useState(false)
  const [assetError, setAssetError] = useState<string | null>(null)

  const [metric, setMetric] = useState<AssetMetricRow | null>(null)
  const [metricLoading, setMetricLoading] = useState(false)
  const [metricError, setMetricError] = useState<string | null>(null)

  useEffect(() => {
    if (!assetId) {
      setAssetError("Invalid asset identifier.")
      return
    }

    if (!accessToken) {
      setAssetError("Sign in to view asset details.")
      return
    }

    const controller = new AbortController()
    setAssetLoading(true)
    setAssetError(null)

    fetch(buildApiUrl(`/v1/assets/${assetId}?include=latest_price`), {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    })
      .then(async (response) => {
        const payload = await parseJson<ApiResponse<AssetShowResponse>>(response)

        if (!response.ok) {
          const message =
            (payload && "message" in payload && payload.message) ||
            "Unable to fetch asset details."
          throw new Error(typeof message === "string" ? message : "Unable to fetch asset details.")
        }

        if (!payload || payload.status !== "success" || !payload.data) {
          throw new Error("Unexpected response from the asset details API.")
        }

        setAsset(payload.data)
      })
      .catch((cause) => {
        if ((cause as Error)?.name === "AbortError") {
          return
        }
        setAssetError(cause instanceof Error ? cause.message : "Unable to load asset details.")
        setAsset(null)
      })
      .finally(() => setAssetLoading(false))

    return () => controller.abort()
  }, [accessToken, assetId])

  useEffect(() => {
    if (!assetId) {
      setMetricError("Invalid asset identifier.")
      return
    }

    if (!accessToken) {
      setMetricError("Sign in to view asset metrics.")
      return
    }

    const controller = new AbortController()
    setMetricLoading(true)
    setMetricError(null)

    fetch(buildApiUrl(`/v1/assets/${assetId}/metrics`), {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    })
      .then(async (response) => {
        const payload = await parseJson<ApiResponse<MetricResponse>>(response)

        if (!response.ok) {
          const message =
            (payload && "message" in payload && payload.message) ||
            "Unable to fetch asset metrics."
          throw new Error(typeof message === "string" ? message : "Unable to fetch asset metrics.")
        }

        if (!payload || payload.status !== "success" || !payload.data?.metric) {
          throw new Error("Metrics for this asset are not available yet.")
        }

        const normalized = normalizeMetrics([payload.data.metric])
        setMetric(normalized[0] ?? null)
      })
      .catch((cause) => {
        if ((cause as Error)?.name === "AbortError") {
          return
        }
        setMetricError(cause instanceof Error ? cause.message : "Unable to load asset metrics.")
        setMetric(null)
      })
      .finally(() => setMetricLoading(false))

    return () => controller.abort()
  }, [accessToken, assetId])

  const latestClose = useMemo(() => {
    if (!asset?.latest_price) {
      return null
    }

    const { close } = asset.latest_price
    if (typeof close === "number") {
      return close
    }
    if (typeof close === "string") {
      const parsed = Number.parseFloat(close)
      return Number.isNaN(parsed) ? null : parsed
    }
    return null
  }, [asset])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Asset details</h1>
          <p className="text-sm text-muted-foreground">
            Dive into an individual symbol to review fundamentals, recent pricing, broker summary, and key metrics.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button type="button" variant="outline" onClick={() => router.back()}>
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back
          </Button>
          <Button asChild variant="secondary">
            <Link href="/dashboard/assets">
              <ExternalLink className="mr-2 h-4 w-4" />
              Asset metrics
            </Link>
          </Button>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle className="text-xl">
                {asset ? `${asset.symbol} · ${asset.name}` : "Asset overview"}
              </CardTitle>
              <CardDescription>Core attributes sourced directly from the assets table.</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1">
              <p className="text-sm font-medium text-muted-foreground">Symbol</p>
              <p className="text-lg font-semibold text-foreground">{asset?.symbol ?? "—"}</p>
            </div>
            <div className="space-y-1">
              <p className="text-sm font-medium text-muted-foreground">Name</p>
              <p className="text-lg font-semibold text-foreground">{asset?.name ?? "—"}</p>
            </div>
            <div className="space-y-1">
              <p className="text-sm font-medium text-muted-foreground">Lot size</p>
              <p className="text-lg font-semibold text-foreground">
                {formatNumber(asset?.lot_size ?? null, integerFormatter)}
              </p>
            </div>
            <div className="space-y-1">
              <p className="text-sm font-medium text-muted-foreground">Tick size</p>
              <p className="text-lg font-semibold text-foreground">
                {formatNumber(asset?.tick_size ?? null, priceFormatter)}
              </p>
            </div>
            <div className="space-y-1">
              <p className="text-sm font-medium text-muted-foreground">Latest close</p>
              <p className="text-lg font-semibold text-foreground">
                {formatNumber(latestClose, priceFormatter)}
              </p>
              {asset?.latest_price?.date ? (
                <p className="text-xs text-muted-foreground">As of {asset.latest_price.date}</p>
              ) : null}
            </div>
          </CardContent>
          {assetLoading ? (
            <div className="px-6 pb-6 text-sm text-muted-foreground">Loading asset details…</div>
          ) : null}
          {assetError ? (
            <div className="px-6 pb-6 text-sm text-destructive">{assetError}</div>
          ) : null}
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Broker summary</CardTitle>
            <CardDescription>
              Overview of recent broker activity for this symbol.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-start gap-3 rounded-md border border-dashed border-border/70 bg-muted/40 p-3">
              <Info className="mt-0.5 h-4 w-4 text-muted-foreground" />
              <div className="space-y-1 text-sm text-muted-foreground">
                <p>Broker summary data is not available yet for this asset.</p>
                <p className="text-xs">
                  When broker summary imports are added, you&apos;ll see the latest net flows and participant breakdown here.
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-3">
          <div>
            <CardTitle>Metrics detail</CardTitle>
            <CardDescription>
              Up-to-date metrics pulled from the metrics table for the selected asset.
            </CardDescription>
          </div>
        </CardHeader>
        <CardContent>
          {metricLoading ? (
            <div className="text-sm text-muted-foreground">Loading metrics…</div>
          ) : metric ? (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <MetricItem label="Close" value={formatNumber(metric.close, priceFormatter)} />
              <MetricItem label="MA 50" value={formatNumber(metric.ma50, priceFormatter)} />
              <MetricItem label="MA 100" value={formatNumber(metric.ma100, priceFormatter)} />
              <MetricItem label="20w High" value={formatNumber(metric.high20, priceFormatter)} />
              <MetricItem label="55w High" value={formatNumber(metric.high55, priceFormatter)} />
              <MetricItem label="ATR 14d" value={formatNumber(metric.atr14, priceFormatter)} />
              <MetricItem label="ROC 13w" value={formatNumber(metric.roc13, ratioFormatter, { suffix: "%" })} />
              <MetricItem label="Avg Vol 20d" value={formatNumber(metric.avgVol20, integerFormatter)} />
              <MetricItem label="Vol / Avg 20d" value={formatNumber(metric.volVsAvg20, ratioFormatter)} />
              <MetricItem label="Close / 20wH" value={formatNumber(metric.closeVsHigh20, ratioFormatter)} />
              <MetricItem label="Close / 55wH" value={formatNumber(metric.closeVsHigh55, ratioFormatter)} />
              <MetricItem
                label="Uptrend"
                value={
                  metric.uptrend === null
                    ? "—"
                    : metric.uptrend
                      ? "Yes"
                      : "No"
                }
              />
              <MetricItem label="Bars" value={formatNumber(metric.bars, integerFormatter)} />
            </div>
          ) : (
            <div className="text-sm text-muted-foreground">
              {metricError ?? "Metrics are not available for this asset yet."}
            </div>
          )}
          {metricError && metricLoading === false ? (
            <p className="mt-3 text-sm text-destructive">{metricError}</p>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}

function MetricItem({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border bg-muted/40 p-3">
      <p className="text-xs uppercase text-muted-foreground">{label}</p>
      <p className="text-lg font-semibold text-foreground">{value}</p>
    </div>
  )
}
