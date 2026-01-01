"use client"

import { useEffect, useMemo, useState } from "react"
import { Edit, Loader2, RefreshCcw, Save, Trash2 } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import {
  createPortfolio,
  createPosition,
  deletePosition,
  fetchPortfolios,
  updatePosition,
  type PortfolioRecord,
  type PositionPayload,
  type PositionRecord,
} from "@/lib/portfolio-client"
import { formatIdr } from "@/lib/currency"

type AssetOption = {
  id: number
  symbol: string
  name: string
}

type FormState = {
  positionId: number | null
  assetId: string
  side: "entry" | "exit"
  qtyShares: string
  price: string
  feeRate: string
  executedAt: string
}

type PortfolioSummary = {
  totalTrades: number
  uniqueAssets: number
  totalEntryValue: number
  totalExitValue: number
  realizedPl: number
}

type AssetIndexResponse = AssetOption[]

const toDateInputValue = (value?: string | null) =>
  value ? value.slice(0, 10) : new Date().toISOString().split("T")[0]

const emptyForm = (): FormState => ({
  positionId: null,
  assetId: "",
  side: "entry",
  qtyShares: "",
  price: "",
  feeRate: "",
  executedAt: toDateInputValue(),
})

const parseNumber = (value: string) => {
  if (!value.trim()) return Number.NaN
  const cleaned = value.replace(/,/g, "")
  const parsed = Number.parseFloat(cleaned)
  return Number.isNaN(parsed) ? Number.NaN : parsed
}

const formatNullableIdr = (value?: number | null) => {
  if (value === null || value === undefined) return "—"
  return formatIdr(value)
}

const formatNullableNumber = (value?: number | null) => {
  if (value === null || value === undefined) return "—"
  return value.toLocaleString("en-US", { maximumFractionDigits: 4 })
}

export default function PortfolioPage() {
  const { accessToken } = useAuth()
  const [portfolio, setPortfolio] = useState<PortfolioRecord | null>(null)
  const [positions, setPositions] = useState<PositionRecord[]>([])
  const [assetSummaries, setAssetSummaries] = useState<PortfolioRecord["asset_summaries"]>([])
  const [assets, setAssets] = useState<AssetOption[]>([])
  const [loading, setLoading] = useState(false)
  const [assetsLoading, setAssetsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [formState, setFormState] = useState<FormState>(emptyForm)

  useEffect(() => {
    if (!accessToken) {
      return
    }

    const controller = new AbortController()
    setAssetsLoading(true)

    fetch(buildApiUrl("/v1/assets"), {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    })
      .then(async (response) => {
        const payload = await parseJson<ApiResponse<AssetIndexResponse>>(response)

        if (!response.ok) {
          const message =
            (payload && "message" in payload && payload.message) || "Unable to load assets."
          throw new Error(typeof message === "string" ? message : "Unable to load assets.")
        }

        if (!payload || payload.status !== "success" || !Array.isArray(payload.data)) {
          throw new Error("Unexpected response from the assets API.")
        }

        const normalized = payload.data
          .map((asset) => ({
            id: asset.id,
            symbol: asset.symbol,
            name: asset.name,
          }))
          .sort((a, b) => a.symbol.localeCompare(b.symbol))

        setAssets(normalized)
      })
      .catch((cause) => {
        if ((cause as Error)?.name === "AbortError") {
          return
        }

        const message =
          cause instanceof Error && cause.message
            ? cause.message
            : "Something went wrong while loading assets."
        setError(message)
      })
      .finally(() => setAssetsLoading(false))

    return () => controller.abort()
  }, [accessToken])

  useEffect(() => {
    if (!accessToken) {
      return
    }

    const loadPortfolio = async () => {
      setLoading(true)
      setError(null)

      try {
        const records = await fetchPortfolios(accessToken, { includePositions: true })
        if (records.length === 0) {
          const created = await createPortfolio(accessToken, {
            name: "Primary Portfolio",
            baseCcy: "IDR",
            remarks: "Primary",
            year: new Date().getFullYear(),
          })
          setPortfolio({ ...created, positions: [], asset_summaries: [] })
          setPositions([])
          setAssetSummaries([])
          return
        }

        const primary = records[0]
        setPortfolio(primary)
        setPositions(primary.positions ?? [])
        setAssetSummaries(primary.asset_summaries ?? [])
      } catch (cause) {
        const message =
          cause instanceof Error && cause.message
            ? cause.message
            : "Unable to load portfolio data."
        setError(message)
      } finally {
        setLoading(false)
      }
    }

    loadPortfolio()
  }, [accessToken])

  useEffect(() => {
    if (formState.assetId || assets.length === 0) {
      return
    }

    setFormState((previous) => ({
      ...previous,
      assetId: String(assets[0].id),
    }))
  }, [assets, formState.assetId])

  const summary = useMemo<PortfolioSummary>(() => {
    const totalTrades = positions.length
    const uniqueAssets = assetSummaries?.length ?? 0
    const totalEntryValue =
      assetSummaries?.reduce((sum, item) => sum + (item.entry.value ?? 0), 0) ?? 0
    const totalExitValue = assetSummaries?.reduce((sum, item) => sum + (item.exit.value ?? 0), 0) ?? 0
    const realizedPl = totalExitValue - totalEntryValue

    return { totalTrades, uniqueAssets, totalEntryValue, totalExitValue, realizedPl }
  }, [assetSummaries, positions])

  const sortedPositions = useMemo(
    () =>
      [...positions].sort((a, b) => {
        const dateA = a.executed_at ? Date.parse(a.executed_at) : 0
        const dateB = b.executed_at ? Date.parse(b.executed_at) : 0
        return dateB - dateA
      }),
    [positions],
  )

  const resetForm = () => {
    setFormState((current) => ({
      ...emptyForm(),
      assetId: current.assetId || (assets[0]?.id ? String(assets[0].id) : ""),
    }))
    setFormError(null)
  }

  const startEdit = (position: PositionRecord) => {
    setFormState({
      positionId: position.id,
      assetId: String(position.asset_id),
      side: position.side,
      qtyShares: String(position.qty_shares),
      price: String(position.price),
      feeRate: String(position.fee_rate ?? ""),
      executedAt: toDateInputValue(position.executed_at),
    })
    setFormError(null)
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setFormError(null)

    if (!accessToken || !portfolio) {
      setFormError("You must be signed in to manage positions.")
      return
    }

    const assetId = Number.parseInt(formState.assetId, 10)
    const qtyShares = parseNumber(formState.qtyShares)
    const price = parseNumber(formState.price)
    const feeRate = formState.feeRate.trim() ? parseNumber(formState.feeRate) : 0
    const executedAt = formState.executedAt.trim()

    if (!Number.isInteger(assetId) || assetId <= 0) {
      setFormError("Select a valid asset.")
      return
    }

    if (!Number.isFinite(qtyShares) || qtyShares <= 0) {
      setFormError("Quantity must be a positive number.")
      return
    }

    if (!Number.isFinite(price) || price <= 0) {
      setFormError("Price must be a positive number.")
      return
    }

    if (!Number.isFinite(feeRate) || feeRate < 0) {
      setFormError("Fee rate must be zero or a positive number.")
      return
    }

    if (!executedAt) {
      setFormError("Execution date is required.")
      return
    }

    const payload: PositionPayload = {
      assetId,
      side: formState.side,
      qtyShares,
      price,
      feeRate: Number.isFinite(feeRate) ? feeRate : undefined,
      executedAt,
    }

    setSaving(true)

    try {
      if (formState.positionId) {
        const updated = await updatePosition(accessToken, portfolio.id, formState.positionId, payload)
        setPositions((previous) => previous.map((item) => (item.id === updated.id ? updated : item)))
      } else {
        const created = await createPosition(accessToken, portfolio.id, payload)
        setPositions((previous) => [...previous, created])
      }

      await refreshData()
      resetForm()
    } catch (cause) {
      const message =
        cause instanceof Error && cause.message
          ? cause.message
          : "Unable to save position. Please try again."
      setFormError(message)
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (position: PositionRecord) => {
    if (!accessToken || !portfolio) {
      setError("You must be signed in to delete positions.")
      return
    }

    setDeletingId(position.id)

    try {
      await deletePosition(accessToken, portfolio.id, position.id)
      setPositions((previous) => previous.filter((item) => item.id !== position.id))
      await refreshData()
      if (formState.positionId === position.id) {
        resetForm()
      }
    } catch (cause) {
      const message =
        cause instanceof Error && cause.message
          ? cause.message
          : "Unable to delete position. Please try again."
      setError(message)
    } finally {
      setDeletingId(null)
    }
  }

  const refreshData = async () => {
    if (!accessToken) return

    setLoading(true)
    setError(null)

    try {
      const records = await fetchPortfolios(accessToken, { includePositions: true })
      if (records.length > 0) {
        setPortfolio(records[0])
        setPositions(records[0].positions ?? [])
        setAssetSummaries(records[0].asset_summaries ?? [])
      } else {
        setPortfolio(null)
        setPositions([])
        setAssetSummaries([])
      }
    } catch (cause) {
      const message =
        cause instanceof Error && cause.message
          ? cause.message
          : "Unable to refresh portfolio data."
      setError(message)
    } finally {
      setLoading(false)
    }
  }

  if (!accessToken) {
    return (
      <div className="space-y-4">
        <div className="rounded-lg bg-destructive/10 p-4 text-destructive">
          Sign in to view and manage your portfolio.
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-8">
      <div className="flex flex-col gap-3">
        <div className="flex items-center gap-3">
          <div className="rounded-lg bg-primary/10 px-3 py-1 text-xs font-semibold uppercase text-primary">
            Portfolio
          </div>
          <p className="text-sm text-muted-foreground">
            Manage your live portfolio with data stored on the backend.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button type="button" variant="secondary" className="gap-2" onClick={refreshData} disabled={loading}>
            {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <RefreshCcw className="size-4" aria-hidden />}
            Refresh
          </Button>
          {error ? <p className="text-sm text-destructive">{error}</p> : null}
        </div>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Total trades</CardTitle>
              <CardDescription>Entries and exits recorded.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.totalTrades}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Assets tracked</CardTitle>
              <CardDescription>Unique assets in this portfolio.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.uniqueAssets}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Entry value</CardTitle>
              <CardDescription>Net of entry fees.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{formatIdr(summary.totalEntryValue)}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Realized P/L</CardTitle>
              <CardDescription>Exit value minus entry value.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{formatIdr(summary.realizedPl)}</CardContent>
          </Card>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1.35fr)]">
        <Card>
          <CardHeader>
            <CardTitle>{formState.positionId ? "Update trade" : "Add a trade"}</CardTitle>
            <CardDescription>Record entries and exits with fees applied automatically.</CardDescription>
          </CardHeader>
          <CardContent>
            <form className="space-y-4" onSubmit={handleSubmit}>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="asset">
                    Asset
                  </label>
                  <select
                    id="asset"
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    value={formState.assetId}
                    onChange={(event) =>
                      setFormState((previous) => ({ ...previous, assetId: event.target.value }))
                    }
                    disabled={assetsLoading || assets.length === 0}
                  >
                    {assets.map((asset) => (
                      <option key={asset.id} value={asset.id}>
                        {asset.symbol} · {asset.name}
                      </option>
                    ))}
                  </select>
                  {assetsLoading ? (
                    <p className="text-xs text-muted-foreground">Loading assets…</p>
                  ) : null}
                  {assets.length === 0 && !assetsLoading ? (
                    <p className="text-xs text-destructive">
                      No assets available. Add assets first to create positions.
                    </p>
                  ) : null}
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="side">
                    Side
                  </label>
                  <select
                    id="side"
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    value={formState.side}
                    onChange={(event) =>
                      setFormState((previous) => ({
                        ...previous,
                        side: event.target.value as FormState["side"],
                      }))
                    }
                  >
                    <option value="entry">Entry</option>
                    <option value="exit">Exit</option>
                  </select>
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="qty-shares">
                    Quantity (shares)
                  </label>
                  <Input
                    id="qty-shares"
                    inputMode="decimal"
                    value={formState.qtyShares}
                    onChange={(event) =>
                      setFormState((previous) => ({ ...previous, qtyShares: event.target.value }))
                    }
                    placeholder="1200"
                    required
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="price">
                    Execution price
                  </label>
                  <Input
                    id="price"
                    inputMode="decimal"
                    value={formState.price}
                    onChange={(event) =>
                      setFormState((previous) => ({ ...previous, price: event.target.value }))
                    }
                    placeholder="8750"
                    required
                  />
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="executed-at">
                    Execution date
                  </label>
                  <Input
                    id="executed-at"
                    type="date"
                    value={formState.executedAt}
                    onChange={(event) =>
                      setFormState((previous) => ({ ...previous, executedAt: event.target.value }))
                    }
                    required
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="fee-rate">
                    Trading fee (%)
                  </label>
                  <Input
                    id="fee-rate"
                    inputMode="decimal"
                    value={formState.feeRate}
                    onChange={(event) =>
                      setFormState((previous) => ({ ...previous, feeRate: event.target.value }))
                    }
                    placeholder="0.2"
                  />
                  <p className="text-xs text-muted-foreground">Fees increase entry prices and reduce exit prices.</p>
                </div>
              </div>

              {formError ? <p className="text-sm text-destructive">{formError}</p> : null}

              <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" className="gap-2" disabled={saving || assets.length === 0}>
                  {saving ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Save className="size-4" aria-hidden />}
                  {formState.positionId ? "Save changes" : "Add position"}
                </Button>
                {formState.positionId ? (
                  <Button type="button" variant="ghost" onClick={resetForm} className="gap-2">
                    <RefreshCcw className="size-4" aria-hidden />
                    Cancel edit
                  </Button>
                ) : null}
              </div>
            </form>
          </CardContent>
        </Card>

        <Card className="space-y-4">
          <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <CardTitle>Trades</CardTitle>
              <CardDescription>View and manage executed entries and exits.</CardDescription>
            </div>
            {loading ? (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" aria-hidden />
                Loading…
              </div>
            ) : null}
          </CardHeader>
          <CardContent className="space-y-3">
            {sortedPositions.length === 0 ? (
              <p className="text-sm text-muted-foreground">No trades yet. Add one to get started.</p>
            ) : (
              sortedPositions.map((position) => {
                const assetLabel = position.asset
                  ? `${position.asset.symbol} · ${position.asset.name}`
                  : `Asset #${position.asset_id}`
                const tradeValue = position.value ?? position.qty_shares * position.avg_price

                return (
                  <div
                    key={position.id}
                    className="rounded-md border bg-background/80 p-3 shadow-sm transition hover:border-primary/50"
                  >
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <p className="text-base font-semibold leading-tight">{assetLabel}</p>
                        <p className="text-xs text-muted-foreground">
                          {position.executed_at ? new Date(position.executed_at).toLocaleDateString() : "—"} ·{" "}
                          {position.side === "exit" ? "Exit" : "Entry"}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <Button variant="secondary" size="sm" className="gap-1" onClick={() => startEdit(position)}>
                          <Edit className="size-4" aria-hidden />
                          Edit
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="gap-1 text-destructive hover:text-destructive"
                          onClick={() => handleDelete(position)}
                          disabled={deletingId === position.id}
                        >
                          {deletingId === position.id ? (
                            <Loader2 className="size-4 animate-spin" aria-hidden />
                          ) : (
                            <Trash2 className="size-4" aria-hidden />
                          )}
                          Remove
                        </Button>
                      </div>
                    </div>

                    <dl className="mt-3 grid gap-3 sm:grid-cols-4">
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Side</dt>
                        <dd className="font-medium capitalize">{position.side}</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Quantity</dt>
                        <dd className="font-medium">{position.qty_shares.toLocaleString("en-US")}</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Execution price</dt>
                        <dd className="font-medium">{formatIdr(position.price)}</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Net price (incl. fee)</dt>
                        <dd className="font-medium">{formatIdr(position.avg_price)}</dd>
                      </div>
                    </dl>

                    <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Trading fee</dt>
                        <dd className="font-medium">{position.fee_rate.toLocaleString("en-US", { maximumFractionDigits: 4 })}%</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Fee value</dt>
                        <dd className="font-medium">{formatIdr(position.fee_value)}</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Trade value</dt>
                        <dd className="font-medium">{formatIdr(tradeValue)}</dd>
                      </div>
                    </dl>
                  </div>
                )
              })
            )}
          </CardContent>
        </Card>
      </div>

      <Card className="space-y-4">
        <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <CardTitle>Portfolio assets</CardTitle>
            <CardDescription>
              Unique assets with consolidated entry and exit summaries{portfolio?.year ? ` for ${portfolio.year}` : ""}.
            </CardDescription>
          </div>
          {portfolio?.remarks ? (
            <span className="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
              {portfolio.remarks}
            </span>
          ) : null}
        </CardHeader>
        <CardContent className="space-y-3">
          {assetSummaries && assetSummaries.length > 0 ? (
            assetSummaries.map((summary, index) => {
              const label = summary.asset
                ? `${summary.asset.symbol} · ${summary.asset.name}`
                : "Unspecified asset"
              const key = summary.asset?.id ?? `asset-${index}`
              const plTone =
                summary.exit.pl_flag === "profit"
                  ? "text-emerald-600 bg-emerald-50"
                  : summary.exit.pl_flag === "loss"
                    ? "text-destructive bg-destructive/10"
                    : "text-muted-foreground bg-muted"

              return (
                <div key={key} className="rounded-md border bg-background/80 p-3 shadow-sm">
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <p className="text-base font-semibold leading-tight">{label}</p>
                      <p className="text-xs text-muted-foreground">
                        Entry value {formatNullableIdr(summary.entry.value)} · Exit value{" "}
                        {formatNullableIdr(summary.exit.value)}
                      </p>
                    </div>
                    <span className={`rounded-full px-2 py-1 text-xs font-semibold capitalize ${plTone}`}>
                      {summary.exit.pl_flag}
                    </span>
                  </div>

                  <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <div className="rounded-md border bg-muted/20 p-3">
                      <p className="text-xs font-semibold uppercase text-muted-foreground">Entry summary</p>
                      <dl className="mt-2 space-y-2 text-sm">
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">Min / Max price</dt>
                          <dd className="font-medium">
                            {formatNullableIdr(summary.entry.min_price)} / {formatNullableIdr(summary.entry.max_price)}
                          </dd>
                        </div>
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">Total shares</dt>
                          <dd className="font-medium">{summary.entry.total_shares.toLocaleString("en-US")}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">Average price</dt>
                          <dd className="font-medium">{formatNullableIdr(summary.entry.average_price)}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">Entry value</dt>
                          <dd className="font-medium">{formatNullableIdr(summary.entry.value)}</dd>
                        </div>
                      </dl>
                    </div>
                    <div className="rounded-md border bg-muted/20 p-3">
                      <p className="text-xs font-semibold uppercase text-muted-foreground">Exit summary</p>
                      <dl className="mt-2 space-y-2 text-sm">
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">Min / Max price</dt>
                          <dd className="font-medium">
                            {formatNullableIdr(summary.exit.min_price)} / {formatNullableIdr(summary.exit.max_price)}
                          </dd>
                        </div>
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">Total shares</dt>
                          <dd className="font-medium">{summary.exit.total_shares.toLocaleString("en-US")}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">Average price</dt>
                          <dd className="font-medium">{formatNullableIdr(summary.exit.average_price)}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">Exit value</dt>
                          <dd className="font-medium">{formatNullableIdr(summary.exit.value)}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                          <dt className="text-muted-foreground">P/L</dt>
                          <dd className="font-medium">
                            {formatNullableIdr(summary.exit.pl_value)}{" "}
                            <span className="text-xs text-muted-foreground">
                              ({formatNullableNumber(summary.exit.pl_percent)}%)
                            </span>
                          </dd>
                        </div>
                      </dl>
                    </div>
                  </div>
                </div>
              )
            })
          ) : (
            <p className="text-sm text-muted-foreground">No asset-level summaries yet.</p>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
