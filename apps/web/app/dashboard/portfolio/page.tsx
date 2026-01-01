"use client"

import { useEffect, useMemo, useState } from "react"
import { Edit, Plus, RefreshCcw, Save, Trash2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import {
  createGroup,
  loadPortfolioState,
  normalizeCurrency,
  persistPortfolioState,
  summarizePortfolio,
  upsertGroupName,
  type PortfolioHolding,
  type PortfolioState,
} from "@/lib/portfolio-storage"

type HoldingFormState = {
  id: string
  symbol: string
  name: string
  shares: string
  averageCost: string
  targetPrice: string
  notes: string
  groupId: string
}

const emptyForm = (): HoldingFormState => ({
  id: "",
  symbol: "",
  name: "",
  shares: "",
  averageCost: "",
  targetPrice: "",
  notes: "",
  groupId: "",
})

const textAreaStyles =
  "flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"

const selectStyles =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"

const parseNumber = (value: string) => {
  if (!value.trim()) return Number.NaN
  const cleaned = value.replace(/,/g, "")
  const parsed = Number.parseFloat(cleaned)
  return Number.isNaN(parsed) ? Number.NaN : parsed
}

export default function PortfolioPage() {
  const [portfolio, setPortfolio] = useState<PortfolioState>(() => loadPortfolioState())
  const [holdingForm, setHoldingForm] = useState<HoldingFormState>(emptyForm)
  const [editingHoldingId, setEditingHoldingId] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [newGroupName, setNewGroupName] = useState("")
  const [groupDrafts, setGroupDrafts] = useState<Record<string, string>>({})

  useEffect(() => {
    setPortfolio(loadPortfolioState())
  }, [])

  useEffect(() => {
    persistPortfolioState(portfolio)
    setGroupDrafts(
      Object.fromEntries(portfolio.groups.map((group) => [group.id, group.name] satisfies [string, string])),
    )

    if (!holdingForm.groupId && portfolio.groups.length > 0) {
      setHoldingForm((previous) => ({
        ...previous,
        groupId: portfolio.groups[0]?.id ?? "",
      }))
    }
  }, [portfolio, holdingForm.groupId])

  const summary = useMemo(() => summarizePortfolio(portfolio), [portfolio])

  const resetForm = () => {
    setHoldingForm((current) => ({
      ...emptyForm(),
      groupId: portfolio.groups[0]?.id ?? current.groupId ?? "",
    }))
    setEditingHoldingId(null)
    setFormError(null)
  }

  const startEditHolding = (holding: PortfolioHolding) => {
    setHoldingForm({
      id: holding.id,
      symbol: holding.symbol,
      name: holding.name,
      shares: holding.shares.toString(),
      averageCost: holding.averageCost.toString(),
      targetPrice: holding.targetPrice !== null ? holding.targetPrice.toString() : "",
      notes: holding.notes,
      groupId: holding.groupId,
    })
    setEditingHoldingId(holding.id)
    setFormError(null)
  }

  const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setFormError(null)

    const symbol = holdingForm.symbol.trim().toUpperCase()
    const name = holdingForm.name.trim()
    const groupId = holdingForm.groupId.trim()
    const shares = parseNumber(holdingForm.shares)
    const averageCost = parseNumber(holdingForm.averageCost)
    const targetPrice = holdingForm.targetPrice.trim() ? parseNumber(holdingForm.targetPrice) : null
    const notes = holdingForm.notes.trim()

    if (!symbol || !name || !groupId) {
      setFormError("Symbol, name, and group are required.")
      return
    }

    if (!Number.isFinite(shares) || shares <= 0) {
      setFormError("Shares must be a positive number.")
      return
    }

    if (!Number.isFinite(averageCost) || averageCost <= 0) {
      setFormError("Average cost must be a positive number.")
      return
    }

    if (targetPrice !== null && (!Number.isFinite(targetPrice) || targetPrice <= 0)) {
      setFormError("Target price must be empty or a positive number.")
      return
    }

    const existing = editingHoldingId
      ? portfolio.holdings.find((holding) => holding.id === editingHoldingId)
      : null

    const payload: PortfolioHolding = {
      id: existing?.id ?? crypto.randomUUID(),
      symbol,
      name,
      shares,
      averageCost,
      targetPrice: targetPrice ?? null,
      notes,
      groupId,
      createdAt: existing?.createdAt ?? new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    }

    setPortfolio((previous) => ({
      ...previous,
      holdings: existing
        ? previous.holdings.map((holding) => (holding.id === existing.id ? payload : holding))
        : [...previous.holdings, payload],
    }))

    resetForm()
  }

  const handleDeleteHolding = (id: string) => {
    setPortfolio((previous) => ({
      ...previous,
      holdings: previous.holdings.filter((holding) => holding.id !== id),
    }))

    if (editingHoldingId === id) {
      resetForm()
    }
  }

  const handleGroupNameChange = (groupId: string, name: string) => {
    setGroupDrafts((previous) => ({
      ...previous,
      [groupId]: name,
    }))
  }

  const handleSaveGroupName = (groupId: string) => {
    const nextName = (groupDrafts[groupId] ?? "").trim()
    if (!nextName) {
      return
    }

    setPortfolio((previous) => ({
      ...previous,
      groups: upsertGroupName(previous.groups, groupId, nextName),
    }))
  }

  const handleCreateGroup = () => {
    const trimmed = newGroupName.trim()
    if (!trimmed) {
      return
    }

    setPortfolio((previous) => ({
      ...previous,
      groups: [...previous.groups, createGroup(previous.groups, trimmed)],
    }))
    setNewGroupName("")
  }

  return (
    <div className="space-y-8">
      <div className="flex flex-col gap-3">
        <div className="flex items-center gap-3">
          <div className="rounded-lg bg-primary/10 px-3 py-1 text-xs font-semibold uppercase text-primary">
            Portfolio
          </div>
          <p className="text-sm text-muted-foreground">
            Track and organize your stock positions by group, add updates, and keep notes per holding.
          </p>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Total Holdings</CardTitle>
              <CardDescription>Active stocks tracked in your list.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.totalHoldings}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Groups</CardTitle>
              <CardDescription>Organize holdings by priority.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.totalGroups}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Primary</CardTitle>
              <CardDescription>Core positions count.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.primaryCount}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Secondary</CardTitle>
              <CardDescription>Rotational or satellite picks.</CardDescription>
            </CardHeader>
            <CardContent className="text-2xl font-semibold">{summary.secondaryCount}</CardContent>
          </Card>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1.4fr)]">
        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>{editingHoldingId ? "Update holding" : "Add a holding"}</CardTitle>
              <CardDescription>
                Capture share count, average cost, and notes for each symbol in your portfolio.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form className="space-y-4" onSubmit={handleSubmit}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <label className="text-sm font-medium" htmlFor="symbol">
                      Symbol
                    </label>
                    <Input
                      id="symbol"
                      value={holdingForm.symbol}
                      onChange={(event) => setHoldingForm((previous) => ({ ...previous, symbol: event.target.value }))}
                      placeholder="e.g., BBCA"
                      required
                      autoComplete="off"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium" htmlFor="name">
                      Company name
                    </label>
                    <Input
                      id="name"
                      value={holdingForm.name}
                      onChange={(event) => setHoldingForm((previous) => ({ ...previous, name: event.target.value }))}
                      placeholder="Bank Central Asia"
                      required
                    />
                  </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <label className="text-sm font-medium" htmlFor="shares">
                      Shares
                    </label>
                    <Input
                      id="shares"
                      inputMode="decimal"
                      value={holdingForm.shares}
                      onChange={(event) => setHoldingForm((previous) => ({ ...previous, shares: event.target.value }))}
                      placeholder="1200"
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium" htmlFor="average-cost">
                      Average cost (IDR)
                    </label>
                    <Input
                      id="average-cost"
                      inputMode="decimal"
                      value={holdingForm.averageCost}
                      onChange={(event) =>
                        setHoldingForm((previous) => ({ ...previous, averageCost: event.target.value }))
                      }
                      placeholder="8750"
                      required
                    />
                  </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <label className="text-sm font-medium" htmlFor="target-price">
                      Target price (optional)
                    </label>
                    <Input
                      id="target-price"
                      inputMode="decimal"
                      value={holdingForm.targetPrice}
                      onChange={(event) =>
                        setHoldingForm((previous) => ({ ...previous, targetPrice: event.target.value }))
                      }
                      placeholder="9500"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium" htmlFor="group">
                      Group
                    </label>
                    <select
                      id="group"
                      className={selectStyles}
                      value={holdingForm.groupId}
                      onChange={(event) => setHoldingForm((previous) => ({ ...previous, groupId: event.target.value }))}
                    >
                      {portfolio.groups.map((group) => (
                        <option key={group.id} value={group.id}>
                          {group.name}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium" htmlFor="notes">
                    Notes
                  </label>
                  <textarea
                    id="notes"
                    className={textAreaStyles}
                    value={holdingForm.notes}
                    onChange={(event) => setHoldingForm((previous) => ({ ...previous, notes: event.target.value }))}
                    placeholder="Why you're holding this stock, key catalysts, or risk controls."
                  />
                </div>

                {formError ? <p className="text-sm text-destructive">{formError}</p> : null}

                <div className="flex flex-wrap items-center gap-3">
                  <Button type="submit" className="gap-2">
                    {editingHoldingId ? (
                      <>
                        <Save className="size-4" aria-hidden />
                        Save changes
                      </>
                    ) : (
                      <>
                        <Plus className="size-4" aria-hidden />
                        Add holding
                      </>
                    )}
                  </Button>
                  {editingHoldingId ? (
                    <Button type="button" variant="ghost" onClick={resetForm} className="gap-2">
                      <RefreshCcw className="size-4" aria-hidden />
                      Reset
                    </Button>
                  ) : null}
                </div>
              </form>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Groups</CardTitle>
              <CardDescription>
                Start with Primary and Secondary, and rename or add groups that fit your strategy.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-3">
                {portfolio.groups.map((group) => (
                  <div key={group.id} className="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center">
                    <div className="flex-1 space-y-1">
                      <label className="text-xs uppercase text-muted-foreground" htmlFor={`group-${group.id}`}>
                        {group.id === "primary" || group.id === "secondary" ? "Default group" : "Custom group"}
                      </label>
                      <Input
                        id={`group-${group.id}`}
                        value={groupDrafts[group.id] ?? group.name}
                        onChange={(event) => handleGroupNameChange(group.id, event.target.value)}
                      />
                      <p className="text-xs text-muted-foreground">
                        Last updated {new Date(group.updatedAt).toLocaleString()}
                      </p>
                    </div>
                    <div className="flex items-center gap-2 self-start sm:self-center">
                      <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        className="gap-2"
                        onClick={() => handleSaveGroupName(group.id)}
                      >
                        <Save className="size-4" aria-hidden />
                        Save
                      </Button>
                    </div>
                  </div>
                ))}
              </div>

              <div className="space-y-2 rounded-md border bg-muted/60 p-3">
                <p className="text-sm font-medium">Add a group</p>
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Input
                    placeholder="e.g., Income, Momentum"
                    value={newGroupName}
                    onChange={(event) => setNewGroupName(event.target.value)}
                  />
                  <Button type="button" className="gap-2 sm:w-40" onClick={handleCreateGroup}>
                    <Plus className="size-4" aria-hidden />
                    Add group
                  </Button>
                </div>
                <p className="text-xs text-muted-foreground">
                  Primary and Secondary are created for you. Add more if you need extra buckets.
                </p>
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="space-y-4">
          <Card>
            <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle>Holdings</CardTitle>
                <CardDescription>Review, edit, or remove your portfolio entries.</CardDescription>
              </div>
              <div className="text-sm text-muted-foreground">
                {summary.latestChange ? `Updated ${summary.latestChange}` : "No updates yet"}
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              {portfolio.groups.map((group) => {
                const holdingsForGroup = portfolio.holdings.filter((holding) => holding.groupId === group.id)

                return (
                  <div key={group.id} className="space-y-2 rounded-lg border p-3">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <p className="text-sm font-semibold">{group.name}</p>
                        <p className="text-xs text-muted-foreground">
                          {holdingsForGroup.length} {holdingsForGroup.length === 1 ? "holding" : "holdings"}
                        </p>
                      </div>
                    </div>

                    <div className="space-y-3">
                      {holdingsForGroup.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No holdings added yet.</p>
                      ) : (
                        holdingsForGroup.map((holding) => (
                          <div
                            key={holding.id}
                            className="rounded-md border bg-background/80 p-3 shadow-sm transition hover:border-primary/50"
                          >
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                              <div>
                                <p className="text-base font-semibold leading-tight">
                                  {holding.symbol} <span className="text-sm text-muted-foreground">· {holding.name}</span>
                                </p>
                                <p className="text-xs text-muted-foreground">
                                  Updated {new Date(holding.updatedAt).toLocaleString()}
                                </p>
                              </div>
                              <div className="flex items-center gap-2">
                                <Button variant="secondary" size="sm" className="gap-1" onClick={() => startEditHolding(holding)}>
                                  <Edit className="size-4" aria-hidden />
                                  Edit
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  className="gap-1 text-destructive hover:text-destructive"
                                  onClick={() => handleDeleteHolding(holding.id)}
                                >
                                  <Trash2 className="size-4" aria-hidden />
                                  Remove
                                </Button>
                              </div>
                            </div>

                            <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                              <div>
                                <dt className="text-xs uppercase text-muted-foreground">Shares</dt>
                                <dd className="font-medium">{holding.shares.toLocaleString("en-US")}</dd>
                              </div>
                              <div>
                                <dt className="text-xs uppercase text-muted-foreground">Average cost</dt>
                                <dd className="font-medium">{normalizeCurrency(holding.averageCost)}</dd>
                              </div>
                              <div>
                                <dt className="text-xs uppercase text-muted-foreground">Target price</dt>
                                <dd className="font-medium">
                                  {holding.targetPrice !== null ? normalizeCurrency(holding.targetPrice) : "—"}
                                </dd>
                              </div>
                            </dl>

                            {holding.notes ? (
                              <p className="mt-2 rounded-md bg-muted/60 p-2 text-sm text-muted-foreground">
                                {holding.notes}
                              </p>
                            ) : null}
                          </div>
                        ))
                      )}
                    </div>
                  </div>
                )
              })}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
