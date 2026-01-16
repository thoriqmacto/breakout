"use client"

import Link from "next/link"
import { useParams, useRouter } from "next/navigation"
import { useEffect, useMemo, useState, type ReactNode } from "react"
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

type AddressPayload = {
  office?: string
  phone?: string
  email?: string[]
  website?: string
}

type HistoryPayload = {
  board?: string
  date?: string
  price?: string | number
  free_float?: string | number
  administrative_bureau?: string
  underwriters?: string[]
}

type AssetDetail = {
  id: number
  symbol: string
  name: string
  lot_size: number | null
  tick_size: number | null
  sector?: string | null
  float?: number | string | null
  ipo_price?: number | string | null
  ipo_date?: string | null
  marketcap?: number | string | null
  background?: string | null
  history?: HistoryPayload | null
  address?: AddressPayload[] | null
  profile_synced_at?: string | null
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
  value: number | string | null | undefined,
  formatter: Intl.NumberFormat,
  options: { suffix?: string } = {},
) => {
  if (value === null || value === undefined) {
    return "—"
  }

  const numericValue = typeof value === "string" ? Number.parseFloat(value.replace(/[^0-9.\-]/g, "")) : value
  if (Number.isNaN(numericValue)) {
    return "—"
  }

  return `${formatter.format(numericValue)}${options.suffix ?? ""}`
}

const compactNumberFormatter = new Intl.NumberFormat("en-US", {
  notation: "compact",
  maximumFractionDigits: 1,
})

const formatPercent = (value: number | string | null | undefined) => {
  if (value === null || value === undefined) {
    return "—"
  }

  const percentValue =
    typeof value === "string" && value.trim().endsWith("%")
      ? Number.parseFloat(value.replace("%", ""))
      : value

  if (percentValue === null || Number.isNaN(percentValue as number)) {
    return typeof value === "string" ? value : "—"
  }

  return `${ratioFormatter.format(percentValue as number)}%`
}

const formatDateString = (value: string | null | undefined) => {
  if (!value) {
    return "—"
  }

  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) {
    return value
  }

  return parsed.toLocaleDateString("en-US", { dateStyle: "medium" })
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
              <CardDescription>Core attributes sourced from the assets table and profile seeders.</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <KeyValue label="Symbol" value={asset?.symbol ?? "—"} />
              <KeyValue label="Name" value={asset?.name ?? "—"} />
              <KeyValue label="Sector" value={asset?.sector ?? "—"} />
              <KeyValue label="Float" value={formatPercent(asset?.float)} />
              <KeyValue label="Market cap" value={formatNumber(asset?.marketcap, compactNumberFormatter)} />
              <KeyValue
                label="Latest close"
                value={formatNumber(latestClose, priceFormatter)}
                hint={asset?.latest_price?.date ? `As of ${asset.latest_price.date}` : undefined}
              />
              <KeyValue label="Lot size" value={formatNumber(asset?.lot_size ?? null, integerFormatter)} />
              <KeyValue label="Tick size" value={formatNumber(asset?.tick_size ?? null, priceFormatter)} />
              <KeyValue label="IPO price" value={formatNumber(asset?.ipo_price, priceFormatter)} />
              <KeyValue label="IPO date" value={formatDateString(asset?.ipo_date)} />
              <KeyValue label="Free float" value={formatPercent(asset?.history?.free_float)} />
              <KeyValue label="Listing board" value={asset?.history?.board ?? "—"} />
            </div>

            {asset?.address?.[0] ? <AddressBlock address={asset.address[0]} /> : null}

            {asset?.history ? (
              <ExpandableSection title="Listing details">
                <div className="grid gap-3 sm:grid-cols-2">
                  <KeyValue label="Listing date" value={formatDateString(asset.history.date)} />
                  <KeyValue label="Offer price" value={formatNumber(asset.history.price, priceFormatter)} />
                  <KeyValue label="Underwriters" value={asset.history.underwriters?.join(", ") ?? "—"} />
                  <KeyValue label="Registrar" value={asset.history.administrative_bureau ?? "—"} />
                </div>
              </ExpandableSection>
            ) : null}

            <ExpandableSection title="Background" defaultExpanded={false} isTextual>
              <p className="text-sm leading-relaxed text-foreground/90">
                {asset?.background ?? "No background information available."}
              </p>
            </ExpandableSection>

            {asset?.profile_synced_at ? (
              <p className="text-xs text-muted-foreground">
                Profile last synced {formatDateString(asset.profile_synced_at)}.
              </p>
            ) : null}
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
              <MetricItem label="PBAS" value={formatNumber(metric.pbas, integerFormatter)} />
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

function KeyValue({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="space-y-1">
      <p className="text-xs uppercase text-muted-foreground">{label}</p>
      <p className="break-words text-base font-semibold leading-tight text-foreground">{value}</p>
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  )
}

function AddressBlock({ address }: { address: AddressPayload }) {
  return (
    <div className="rounded-md border bg-muted/40 p-3">
      <p className="text-xs uppercase text-muted-foreground">Registered office</p>
      <p className="mt-1 text-sm font-medium leading-relaxed text-foreground">
        {address.office ?? "—"}
      </p>
      <div className="mt-2 space-y-1 text-xs text-muted-foreground">
        {address.phone ? <p>Phone: {address.phone}</p> : null}
        {address.email?.length ? <p>Email: {address.email.join(", ")}</p> : null}
        {address.website ? (
          <p>
            Website:{" "}
            <a
              className="text-primary underline-offset-2 hover:underline"
              href={address.website}
              target="_blank"
              rel="noreferrer"
            >
              {address.website}
            </a>
          </p>
        ) : null}
      </div>
    </div>
  )
}

function ExpandableSection({
  title,
  children,
  defaultExpanded = true,
  isTextual = false,
}: {
  title: string
  children: ReactNode
  defaultExpanded?: boolean
  isTextual?: boolean
}) {
  const [expanded, setExpanded] = useState(defaultExpanded)

  return (
    <div className="space-y-2 rounded-md border border-dashed border-border/60 p-3">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-medium text-foreground">{title}</p>
        <Button
          variant="ghost"
          size="sm"
          className="-mr-2 h-8 px-2 text-xs"
          onClick={() => setExpanded((prev) => !prev)}
        >
          {expanded ? "Hide" : "Show"}
        </Button>
      </div>
      {expanded ? (
        <div className={isTextual ? "text-sm text-foreground/90" : "text-sm text-foreground"}>
          {children}
        </div>
      ) : (
        <p className="text-xs text-muted-foreground">
          Compact view enabled. Select &quot;Show&quot; to see more details.
        </p>
      )}
    </div>
  )
}
