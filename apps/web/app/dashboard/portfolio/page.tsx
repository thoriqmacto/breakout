"use client"

import { useEffect, useMemo, useState } from "react"
import { Ban, CheckCircle2, Edit, Loader2, RefreshCcw, Save, Trash2 } from "lucide-react"

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
  side: "long" | "short"
  qtyShares: string
  avgPrice: string
  entryDate: string
  trail: string
  status: "open" | "closed"
}

type PortfolioSummary = {
  totalPositions: number
  openPositions: number
  closedPositions: number
  totalNotional: number
}

type AssetIndexResponse = AssetOption[]

const toDateInputValue = (value?: string | null) =>
  value ? value.slice(0, 10) : new Date().toISOString().split("T")[0]

const emptyForm = (): FormState => ({
  positionId: null,
  assetId: "",
  side: "long",
  qtyShares: "",
  avgPrice: "",
  entryDate: toDateInputValue(),
  trail: "",
  status: "open",
})

const parseNumber = (value: string) => {
  if (!value.trim()) return Number.NaN
  const cleaned = value.replace(/,/g, "")
  const parsed = Number.parseFloat(cleaned)
  return Number.isNaN(parsed) ? Number.NaN : parsed
}

export default function PortfolioPage() {
  const { accessToken } = useAuth()
  const [portfolio, setPortfolio] = useState<PortfolioRecord | null>(null)
  const [positions, setPositions] = useState<PositionRecord[]>([])
  const [assets, setAssets] = useState<AssetOption[]>([])
  const [loading, setLoading] = useState(false)
  const [assetsLoading, setAssetsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [statusUpdatingId, setStatusUpdatingId] = useState<number | null>(null)
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
          })
          setPortfolio({ ...created, positions: [] })
          setPositions([])
          return
        }

        const primary = records[0]
        setPortfolio(primary)
        setPositions(primary.positions ?? [])
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
    const totalPositions = positions.length
    const openPositions = positions.filter((position) => position.status === "open").length
    const closedPositions = totalPositions - openPositions
    const totalNotional = positions.reduce(
      (sum, position) => sum + (position.notional_value ?? position.qty_shares * position.avg_price),
      0,
    )

    return { totalPositions, openPositions, closedPositions, totalNotional }
  }, [positions])

  const sortedPositions = useMemo(
    () =>
      [...positions].sort((a, b) => {
        const dateA = a.entry_date ? Date.parse(a.entry_date) : 0
        const dateB = b.entry_date ? Date.parse(b.entry_date) : 0
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
      avgPrice: String(position.avg_price),
      entryDate: toDateInputValue(position.entry_date),
      trail: position.trail !== null ? String(position.trail) : "",
      status: position.status,
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
    const avgPrice = parseNumber(formState.avgPrice)
    const trail = formState.trail.trim() ? parseNumber(formState.trail) : null
    const entryDate = formState.entryDate.trim()

    if (!Number.isInteger(assetId) || assetId <= 0) {
      setFormError("Select a valid asset.")
      return
    }

    if (!Number.isFinite(qtyShares) || qtyShares <= 0) {
      setFormError("Quantity must be a positive number.")
      return
    }

    if (!Number.isFinite(avgPrice) || avgPrice <= 0) {
      setFormError("Average price must be a positive number.")
      return
    }

    if (!entryDate) {
      setFormError("Entry date is required.")
      return
    }

    const payload: PositionPayload = {
      assetId,
      side: formState.side,
      qtyShares,
      avgPrice,
      entryDate,
      trail: trail === null ? null : trail,
      status: formState.status,
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

  const handleStatusChange = async (position: PositionRecord, nextStatus: PositionRecord["status"]) => {
    if (!accessToken || !portfolio) {
      setError("You must be signed in to update positions.")
      return
    }

    setStatusUpdatingId(position.id)

    try {
      const updated = await updatePosition(accessToken, portfolio.id, position.id, {
        assetId: position.asset_id,
        side: position.side,
        qtyShares: position.qty_shares,
        avgPrice: position.avg_price,
        entryDate: position.entry_date ?? toDateInputValue(),
        trail: position.trail,
        status: nextStatus,
      })

      setPositions((previous) => previous.map((item) => (item.id === updated.id ? updated : item)))
      if (formState.positionId === position.id) {
        setFormState((prev) => ({ ...prev, status: nextStatus }))
      }
    } catch (cause) {
      const message =
        cause instanceof Error && cause.message
          ? cause.message
          : "Unable to update position status. Please try again."
      setError(message)
    } finally {
      setStatusUpdatingId(null)
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
              <CardTitle className="text-lg">Total positions</CardTitle>
              <CardDescription>All tracked entries.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.totalPositions}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Open positions</CardTitle>
              <CardDescription>Currently active positions.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.openPositions}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Closed positions</CardTitle>
              <CardDescription>Positions marked as closed.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.closedPositions}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Total notional</CardTitle>
              <CardDescription>Quantity × average price.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{formatIdr(summary.totalNotional)}</CardContent>
          </Card>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1.35fr)]">
        <Card>
          <CardHeader>
            <CardTitle>{formState.positionId ? "Update position" : "Add a position"}</CardTitle>
            <CardDescription>
              Store positions against your backend portfolio. Assets and quantities are validated before saving.
            </CardDescription>
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
                    <option value="long">Long</option>
                    <option value="short">Short</option>
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
                  <label className="text-sm font-medium" htmlFor="avg-price">
                    Average price
                  </label>
                  <Input
                    id="avg-price"
                    inputMode="decimal"
                    value={formState.avgPrice}
                    onChange={(event) =>
                      setFormState((previous) => ({ ...previous, avgPrice: event.target.value }))
                    }
                    placeholder="8750"
                    required
                  />
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="entry-date">
                    Entry date
                  </label>
                  <Input
                    id="entry-date"
                    type="date"
                    value={formState.entryDate}
                    onChange={(event) =>
                      setFormState((previous) => ({ ...previous, entryDate: event.target.value }))
                    }
                    required
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="trail">
                    Trail (optional)
                  </label>
                  <Input
                    id="trail"
                    inputMode="decimal"
                    value={formState.trail}
                    onChange={(event) =>
                      setFormState((previous) => ({ ...previous, trail: event.target.value }))
                    }
                    placeholder="0"
                  />
                </div>
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium" htmlFor="status">
                  Status
                </label>
                <select
                  id="status"
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                  value={formState.status}
                  onChange={(event) =>
                    setFormState((previous) => ({
                      ...previous,
                      status: event.target.value as FormState["status"],
                    }))
                  }
                >
                  <option value="open">Open</option>
                  <option value="closed">Closed</option>
                </select>
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
              <CardTitle>Positions</CardTitle>
              <CardDescription>View and manage positions saved to the API.</CardDescription>
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
              <p className="text-sm text-muted-foreground">No positions yet. Add one to get started.</p>
            ) : (
              sortedPositions.map((position) => {
                const assetLabel = position.asset
                  ? `${position.asset.symbol} · ${position.asset.name}`
                  : `Asset #${position.asset_id}`

                return (
                  <div
                    key={position.id}
                    className="rounded-md border bg-background/80 p-3 shadow-sm transition hover:border-primary/50"
                  >
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <p className="text-base font-semibold leading-tight">{assetLabel}</p>
                        <p className="text-xs text-muted-foreground">
                          Entry {position.entry_date ? new Date(position.entry_date).toLocaleDateString() : "—"}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <Button
                          variant={position.status === "closed" ? "outline" : "secondary"}
                          size="sm"
                          className="gap-1"
                          onClick={() =>
                            handleStatusChange(position, position.status === "open" ? "closed" : "open")
                          }
                          disabled={statusUpdatingId === position.id}
                        >
                          {statusUpdatingId === position.id ? (
                            <Loader2 className="size-4 animate-spin" aria-hidden />
                          ) : position.status === "closed" ? (
                            <>
                              <CheckCircle2 className="size-4" aria-hidden />
                              Mark open
                            </>
                          ) : (
                            <>
                              <Ban className="size-4" aria-hidden />
                              Mark closed
                            </>
                          )}
                        </Button>
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
                        <dt className="text-xs uppercase text-muted-foreground">Average price</dt>
                        <dd className="font-medium">{formatIdr(position.avg_price)}</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Notional</dt>
                        <dd className="font-medium">
                          {formatIdr(position.notional_value ?? position.qty_shares * position.avg_price)}
                        </dd>
                      </div>
                    </dl>

                    <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Entry date</dt>
                        <dd className="font-medium">
                          {position.entry_date ? new Date(position.entry_date).toLocaleDateString() : "—"}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Trail</dt>
                        <dd className="font-medium">
                          {position.trail !== null ? position.trail.toLocaleString("en-US") : "—"}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-muted-foreground">Status</dt>
                        <dd className="flex items-center gap-2 font-medium">
                          {position.status === "closed" ? (
                            <>
                              <Ban className="size-4 text-destructive" aria-hidden />
                              Closed
                            </>
                          ) : (
                            <>
                              <CheckCircle2 className="size-4 text-emerald-500" aria-hidden />
                              Open
                            </>
                          )}
                        </dd>
                      </div>
                    </dl>
                  </div>
                )
              })
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
