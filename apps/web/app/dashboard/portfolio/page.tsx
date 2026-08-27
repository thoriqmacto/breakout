"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Edit, FileJson, Loader2, Plus, RefreshCcw, Save, Trash2 } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { AddAssetButton } from "@/components/add-asset-button"
import { CashMovementsCard } from "@/components/cash-movements-card"
import { PortfolioSummaryCard } from "@/components/portfolio-summary-card"
import { StockbitImportDialog } from "@/components/portfolio/stockbit-import-dialog"
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

const formatNullableDate = (value?: string | null) => {
  if (!value) return "—"
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? "—" : parsed.toLocaleDateString("en-US")
}

const getPositionStatus = (entryShares: number, exitShares: number) =>
  entryShares === exitShares ? "CLOSE" : "OPEN"

export default function PortfolioPage() {
  const { accessToken } = useAuth()
  const [portfolios, setPortfolios] = useState<PortfolioRecord[]>([])
  const [selectedPortfolioId, setSelectedPortfolioId] = useState<number | null>(null)
  const [portfolio, setPortfolio] = useState<PortfolioRecord | null>(null)
  const [positions, setPositions] = useState<PositionRecord[]>([])
  const [assetSummaries, setAssetSummaries] = useState<PortfolioRecord["asset_summaries"]>([])
  const [assets, setAssets] = useState<AssetOption[]>([])
  const [loading, setLoading] = useState(false)
  const [portfoliosLoading, setPortfoliosLoading] = useState(false)
  const [assetsLoading, setAssetsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [creatingPortfolio, setCreatingPortfolio] = useState(false)
  const [formState, setFormState] = useState<FormState>(emptyForm)
  const [summaryRevision, setSummaryRevision] = useState(0)
  const [importOpen, setImportOpen] = useState(false)

  const handleAssetCreated = useCallback(
    (asset: AssetOption) => {
      setAssets((previous) => {
        const filtered = previous.filter((item) => item.id !== asset.id)
        const next = [...filtered, asset].sort((a, b) => a.symbol.localeCompare(b.symbol))
        return next
      })

      setFormState((previous) => ({
        ...previous,
        assetId: String(asset.id),
      }))
    },
    [],
  )

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

    let isCancelled = false

    const loadPortfolios = async () => {
      setPortfoliosLoading(true)
      setLoading(true)
      setError(null)

      try {
        const records = await fetchPortfolios(accessToken, { includePositions: true })
        if (isCancelled) return
        setPortfolios(records)
        setSelectedPortfolioId((currentSelectedId) => {
          if (records.length === 0) {
            return null
          }

          if (currentSelectedId && records.some((item) => item.id === currentSelectedId)) {
            return currentSelectedId
          }

          return records[0].id
        })
      } catch (cause) {
        if (isCancelled) {
          return
        }

        const message =
          cause instanceof Error && cause.message
            ? cause.message
            : "Unable to load portfolio data."
        setError(message)
      } finally {
        if (!isCancelled) {
          setPortfoliosLoading(false)
          setLoading(false)
        }
      }
    }

    loadPortfolios()

    return () => {
      isCancelled = true
    }
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

  useEffect(() => {
    const current = portfolios.find((item) => item.id === selectedPortfolioId) ?? null
    setPortfolio(current ?? null)
    setPositions(current?.positions ?? [])
    setAssetSummaries(current?.asset_summaries ?? [])
  }, [portfolios, selectedPortfolioId])

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
        // Prefer the full timestamp: imported fills can share a date, and the
        // order between them is meaningful.
        const dateA = Date.parse(a.executed_at_iso ?? a.executed_at ?? "") || 0
        const dateB = Date.parse(b.executed_at_iso ?? b.executed_at ?? "") || 0
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

  const refreshData = async (preferredPortfolioId?: number) => {
    if (!accessToken) return

    setLoading(true)
    setError(null)

    try {
      const records = await fetchPortfolios(accessToken, { includePositions: true })
      setPortfolios(records)

      if (records.length === 0) {
        setPortfolio(null)
        setPositions([])
        setAssetSummaries([])
        setSelectedPortfolioId(null)
        return
      }

      const targetId = preferredPortfolioId && records.some((item) => item.id === preferredPortfolioId)
        ? preferredPortfolioId
        : selectedPortfolioId && records.some((item) => item.id === selectedPortfolioId)
          ? selectedPortfolioId
          : records[0].id

      setSelectedPortfolioId(targetId)
      const current = records.find((item) => item.id === targetId) ?? null
      setPortfolio(current)
      setPositions(current?.positions ?? [])
      setAssetSummaries(current?.asset_summaries ?? [])
      setSummaryRevision((r) => r + 1)
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

  const handleCreatePortfolio = async () => {
    if (!accessToken) {
      setError("Sign in to create a portfolio.")
      return
    }

    setCreatingPortfolio(true)
    setError(null)

    try {
      const nextIndex = portfolios.length + 1
      const created = await createPortfolio(accessToken, {
        name: `Portfolio ${nextIndex}`,
        baseCcy: "IDR",
        remarks: "",
        year: new Date().getFullYear(),
      })

      await refreshData(created.id)
    } catch (cause) {
      const message =
        cause instanceof Error && cause.message
          ? cause.message
          : "Unable to create portfolio."
      setError(message)
    } finally {
      setCreatingPortfolio(false)
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
      {importOpen && portfolio && accessToken ? (
        <StockbitImportDialog
          accessToken={accessToken}
          portfolioId={portfolio.id}
          portfolioName={portfolio.name}
          onClose={() => setImportOpen(false)}
          onImported={() => refreshData(portfolio.id)}
        />
      ) : null}

      <div className="flex flex-col gap-3">
        <div className="flex items-center gap-3">
          <div className="rounded-lg bg-primary/10 px-3 py-1 text-xs font-semibold uppercase text-primary">
            Portfolio
          </div>
          <p className="text-sm text-muted-foreground">
            View all portfolios, then drill into one to manage trades and positions.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button type="button" variant="secondary" className="gap-2" onClick={() => refreshData()} disabled={loading}>
            {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <RefreshCcw className="size-4" aria-hidden />}
            Refresh
          </Button>
          <Button type="button" className="gap-2" onClick={handleCreatePortfolio} disabled={creatingPortfolio}>
            {creatingPortfolio ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Plus className="size-4" aria-hidden />}
            New portfolio
          </Button>
          <Button
            type="button"
            variant="outline"
            className="gap-2"
            onClick={() => setImportOpen(true)}
            disabled={!portfolio}
            title={portfolio ? undefined : "Select a portfolio first."}
          >
            <FileJson className="size-4" aria-hidden />
            Import JSON
          </Button>
          {error ? <p className="text-sm text-destructive">{error}</p> : null}
        </div>
      </div>

      <Card>
        <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <CardTitle>Portfolios</CardTitle>
            <CardDescription>Choose a portfolio to view its assets and trades.</CardDescription>
          </div>
          {portfoliosLoading ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" aria-hidden />
              Loading…
            </div>
          ) : null}
        </CardHeader>
        <CardContent>
          {portfolios.length === 0 ? (
            <p className="text-sm text-muted-foreground">No portfolios found. Create one to get started.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase text-muted-foreground">
                    <th className="px-3 py-2 font-medium">Name</th>
                    <th className="px-3 py-2 font-medium">Base CCY</th>
                    <th className="px-3 py-2 font-medium">Year</th>
                    <th className="px-3 py-2 font-medium">Assets</th>
                    <th className="px-3 py-2 font-medium">Trades</th>
                    <th className="px-3 py-2 font-medium text-right">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {portfolios.map((item) => {
                    const isSelected = item.id === selectedPortfolioId
                    const tradesCount = item.positions?.length ?? item.positions_count ?? 0
                    const assetsCount = item.asset_summaries?.length ?? 0

                    return (
                      <tr key={item.id} className={`border-b ${isSelected ? "bg-primary/5" : ""}`}>
                        <td className="px-3 py-2 text-sm font-medium">
                          <div>{item.name}</div>
                          {item.remarks ? (
                            <p className="text-xs text-muted-foreground">{item.remarks}</p>
                          ) : null}
                        </td>
                        <td className="px-3 py-2 text-sm">{item.base_ccy}</td>
                        <td className="px-3 py-2 text-sm">{item.year ?? "—"}</td>
                        <td className="px-3 py-2 text-sm">{assetsCount}</td>
                        <td className="px-3 py-2 text-sm">{tradesCount}</td>
                        <td className="px-3 py-2 text-right">
                          <Button
                            variant={isSelected ? "secondary" : "outline"}
                            size="sm"
                            onClick={() => setSelectedPortfolioId(item.id)}
                            disabled={loading}
                          >
                            {isSelected ? "Selected" : "View details"}
                          </Button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {portfolio ? (
        <div className="space-y-6">
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

          {accessToken ? (
            <PortfolioSummaryCard
              accessToken={accessToken}
              portfolioId={selectedPortfolioId}
              revision={summaryRevision}
            />
          ) : null}

          {accessToken ? (
            <CashMovementsCard
              accessToken={accessToken}
              portfolioId={selectedPortfolioId}
              onChange={() => setSummaryRevision((r) => r + 1)}
            />
          ) : null}

          <Card>
            <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle>Portfolio assets</CardTitle>
                <CardDescription>
                  Unique assets with consolidated entry and exit summaries{portfolio.year ? ` for ${portfolio.year}` : ""}.
                </CardDescription>
              </div>
              {portfolio.remarks ? (
                <span className="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                  {portfolio.remarks}
                </span>
              ) : null}
            </CardHeader>
            <CardContent>
              {assetSummaries && assetSummaries.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b text-left text-xs uppercase text-muted-foreground">
                        <th className="px-3 py-2 font-medium">Status</th>
                        <th className="px-3 py-2 font-medium">Asset</th>
                        <th className="px-3 py-2 font-medium">Entry summary</th>
                        <th className="px-3 py-2 font-medium">Exit summary</th>
                      </tr>
                    </thead>
                    <tbody>
                      {assetSummaries.map((item, index) => {
                        const label = item.asset
                          ? `${item.asset.symbol} · ${item.asset.name}`
                          : "Unspecified asset"
                        const key = item.asset?.id ?? `asset-${index}`
                        const status = getPositionStatus(item.entry.total_shares, item.exit.total_shares)
                        const hasExitTrades = item.exit.total_shares > 0
                        const plBadgeClasses = hasExitTrades
                          ? item.exit.pl_flag === "profit"
                            ? "bg-emerald-100 text-emerald-700"
                            : item.exit.pl_flag === "loss"
                              ? "bg-red-100 text-red-700"
                              : "bg-amber-100 text-amber-700"
                          : "bg-muted text-muted-foreground"

                        return (
                          <tr key={key} className="border-b align-top">
                            <td className="px-3 py-3 text-sm font-semibold uppercase">
                              <span
                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] tracking-wide ${
                                  status === "CLOSE"
                                    ? "bg-emerald-100 text-emerald-700"
                                    : "bg-amber-100 text-amber-800"
                                }`}
                              >
                                {status}
                              </span>
                            </td>
                            <td className="px-3 py-3 text-sm font-medium">
                              <div>{label}</div>
                              <div className="text-xs text-muted-foreground">
                                Latest close: {formatNullableIdr(item.exit.latest_close)}{" "}
                                <span className="text-[11px]">
                                  ({formatNullableDate(item.exit.latest_close_date)})
                                </span>
                              </div>
                            </td>
                            <td className="px-3 py-3 text-sm">
                              <div className="text-xs text-muted-foreground">Min/Max price</div>
                              <div className="font-medium">
                                {formatNullableIdr(item.entry.min_price)} / {formatNullableIdr(item.entry.max_price)}
                              </div>
                              <div className="mt-2 text-xs text-muted-foreground">Total shares</div>
                              <div className="font-medium">{item.entry.total_shares.toLocaleString("en-US")}</div>
                              <div className="mt-2 text-xs text-muted-foreground">Avg entry price</div>
                              <div className="font-medium">{formatNullableIdr(item.entry.average_price)}</div>
                              <div className="mt-2 text-xs text-muted-foreground">Entry value</div>
                              <div className="font-medium">{formatNullableIdr(item.entry.value)}</div>
                            </td>
                            <td className="px-3 py-3 text-sm">
                              <div className="text-xs text-muted-foreground">Min/Max price</div>
                              <div className="font-medium">
                                {hasExitTrades ? (
                                  <>
                                    {formatNullableIdr(item.exit.min_price)} / {formatNullableIdr(item.exit.max_price)}
                                  </>
                                ) : (
                                  "—"
                                )}
                              </div>
                              <div className="mt-2 text-xs text-muted-foreground">Total shares</div>
                              <div className="font-medium">
                                {hasExitTrades ? item.exit.total_shares.toLocaleString("en-US") : "—"}
                              </div>
                              <div className="mt-2 text-xs text-muted-foreground">Avg exit price</div>
                              <div className="font-medium">
                                {hasExitTrades ? formatNullableIdr(item.exit.average_price) : "—"}
                              </div>
                              <div className="mt-2 text-xs text-muted-foreground">Exit value</div>
                              <div className="font-medium">
                                {hasExitTrades ? formatNullableIdr(item.exit.value) : "—"}
                              </div>
                              <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                                <span>P/L</span>
                                <span
                                  className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ${plBadgeClasses}`}
                                >
                                  {hasExitTrades ? item.exit.pl_flag : "—"}
                                </span>
                              </div>
                              {hasExitTrades ? (
                                <div className="font-medium">
                                  {formatNullableIdr(item.exit.pl_value)}{" "}
                                  <span className="text-xs text-muted-foreground">
                                    ({formatNullableNumber(item.exit.pl_percent)}%)
                                  </span>
                                </div>
                              ) : (
                                <div className="font-medium">—</div>
                              )}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">No asset-level summaries yet.</p>
              )}
            </CardContent>
          </Card>

          <Card className="space-y-4">
            <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle>Trades</CardTitle>
                <CardDescription>View, add, and edit executed entries and exits.</CardDescription>
              </div>
              {loading ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Loader2 className="size-4 animate-spin" aria-hidden />
                  Updating…
                </div>
              ) : null}
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="rounded-md border bg-muted/30 p-4">
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
                  <AddAssetButton
                    className="pt-1"
                    buttonVariant="outline"
                    onCreated={(asset) => handleAssetCreated({ id: asset.id, symbol: asset.symbol, name: asset.name })}
                  />
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
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase text-muted-foreground">
                      <th className="px-3 py-2 font-medium">Date</th>
                      <th className="px-3 py-2 font-medium">Asset</th>
                      <th className="px-3 py-2 font-medium">Side</th>
                      <th className="px-3 py-2 font-medium text-right">Quantity</th>
                      <th className="px-3 py-2 font-medium text-right">Execution price</th>
                      <th className="px-3 py-2 font-medium text-right">Fee (%)</th>
                      <th className="px-3 py-2 font-medium text-right">Net price</th>
                      <th className="px-3 py-2 font-medium text-right">Trade value</th>
                      <th className="px-3 py-2 font-medium text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sortedPositions.length === 0 ? (
                      <tr>
                        <td className="px-3 py-4 text-sm text-muted-foreground" colSpan={9}>
                          No trades yet. Add one to get started.
                        </td>
                      </tr>
                    ) : (
                      sortedPositions.map((position) => {
                        const assetLabel = position.asset
                          ? `${position.asset.symbol} · ${position.asset.name}`
                          : `Asset #${position.asset_id}`
                        const tradeValue = position.value ?? position.qty_shares * position.avg_price

                        return (
                          <tr key={position.id} className="border-b">
                            <td className="px-3 py-2 text-sm">
                              {position.executed_at ? new Date(position.executed_at).toLocaleDateString() : "—"}
                            </td>
                            <td className="px-3 py-2 text-sm font-medium">{assetLabel}</td>
                            <td className="px-3 py-2 text-sm capitalize">{position.side}</td>
                            <td className="px-3 py-2 text-right text-sm">{position.qty_shares.toLocaleString("en-US")}</td>
                            <td className="px-3 py-2 text-right text-sm">{formatIdr(position.price)}</td>
                            <td className="px-3 py-2 text-right text-sm">{position.fee_rate.toLocaleString("en-US", { maximumFractionDigits: 4 })}%</td>
                            <td className="px-3 py-2 text-right text-sm">{formatIdr(position.avg_price)}</td>
                            <td className="px-3 py-2 text-right text-sm">{formatIdr(tradeValue)}</td>
                            <td className="px-3 py-2 text-right">
                              <div className="flex justify-end gap-2">
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
                            </td>
                          </tr>
                        )
                      })
                    )}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </div>
      ) : (
        <Card>
          <CardContent className="py-6 text-sm text-muted-foreground">
            Select a portfolio above to view its details and manage trades.
          </CardContent>
        </Card>
      )}
    </div>
  )
}
