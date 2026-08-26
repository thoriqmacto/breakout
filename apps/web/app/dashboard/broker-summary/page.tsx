"use client"

import { useCallback, useEffect, useState } from "react"
import { RefreshCw, TriangleAlert } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import {
  CoverageNote,
  DetectorPanel,
  EntriesTable,
  WindowBadge,
  WindowCard,
} from "@/components/broker-summary"
import {
  fetchEntries,
  fetchWindow,
  fetchWindows,
  formatWindow,
  type BrokerEntry,
  type BrokerSide,
  type BrokerWindow,
  type PageMeta,
} from "@/lib/broker-summary-client"

type View = "overview" | "entries" | "detector"

const VIEWS: { key: View; label: string }[] = [
  { key: "overview", label: "Overview" },
  { key: "entries", label: "Broker entries" },
  { key: "detector", label: "Bandar detector" },
]

export default function BrokerSummaryPage() {
  const { accessToken } = useAuth()

  const [view, setView] = useState<View>("overview")
  const [symbol, setSymbol] = useState("")
  const [windowFrom, setWindowFrom] = useState("")
  const [windowTo, setWindowTo] = useState("")
  const [match, setMatch] = useState<"exact" | "overlap">("overlap")

  const [windows, setWindows] = useState<BrokerWindow[]>([])
  const [selected, setSelected] = useState<BrokerWindow | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [entries, setEntries] = useState<BrokerEntry[]>([])
  const [entriesMeta, setEntriesMeta] = useState<PageMeta | null>(null)
  const [entriesPage, setEntriesPage] = useState(1)
  const [broker, setBroker] = useState("")
  const [side, setSide] = useState<BrokerSide | "">("")

  const filters = {
    symbol: symbol.trim().toUpperCase() || undefined,
    windowFrom: windowFrom || undefined,
    windowTo: windowTo || undefined,
    match,
  }

  const loadWindows = useCallback(async () => {
    if (!accessToken) return
    setLoading(true)
    setError(null)
    try {
      const result = await fetchWindows(accessToken, { ...filters, perPage: 24 })
      setWindows(result.windows)

      // Selecting the newest window keeps the detail views populated without
      // making the operator click twice for the common case.
      setSelected((current) => {
        const stillListed = result.windows.find((w) => w.id === current?.id)
        return stillListed ?? result.windows[0] ?? null
      })
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to load broker summaries")
    } finally {
      setLoading(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessToken, symbol, windowFrom, windowTo, match])

  useEffect(() => {
    void loadWindows()
  }, [loadWindows])

  // Buyers and sellers are fetched per window rather than shipped with the
  // list, so opening the page never pulls every broker of every window.
  const loadSelected = useCallback(
    async (id: number) => {
      if (!accessToken) return
      try {
        const result = await fetchWindow(accessToken, id)
        setSelected(result.window)
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : "Unable to load that window")
      }
    },
    [accessToken],
  )

  useEffect(() => {
    if (selected && selected.buyers === undefined) {
      void loadSelected(selected.id)
    }
  }, [selected, loadSelected])

  const loadEntries = useCallback(async () => {
    if (!accessToken || view !== "entries") return
    setLoading(true)
    setError(null)
    try {
      const result = await fetchEntries(accessToken, {
        ...filters,
        broker: broker.trim().toUpperCase() || undefined,
        side: side || undefined,
        page: entriesPage,
        perPage: 50,
      })
      setEntries(result.entries)
      setEntriesMeta(result.meta)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to load broker entries")
    } finally {
      setLoading(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessToken, view, symbol, windowFrom, windowTo, match, broker, side, entriesPage])

  useEffect(() => {
    void loadEntries()
  }, [loadEntries])

  return (
    <div className="space-y-6 p-6 lg:p-8">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-primary">Market microstructure</p>
          <h1 className="text-3xl font-semibold tracking-tight">Broker summary</h1>
          <p className="mt-2 max-w-3xl text-muted-foreground">
            Each summary is a Stockbit aggregate over a <strong>period</strong>, not a trading day.
            The broker figures cover the whole range shown, and a single-day summary is simply a
            period whose start and end match.
          </p>
        </div>
        <Button variant="outline" onClick={() => void loadWindows()} disabled={loading}>
          <RefreshCw className={loading ? "animate-spin" : ""} /> Refresh
        </Button>
      </div>

      <Card>
        <CardContent className="grid gap-3 py-5 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label className="mb-1 block text-xs text-muted-foreground">Symbol</label>
            <Input value={symbol} onChange={(e) => setSymbol(e.target.value)} placeholder="BRPT" />
          </div>
          <div>
            <label className="mb-1 block text-xs text-muted-foreground">Period from</label>
            <Input type="date" value={windowFrom} onChange={(e) => setWindowFrom(e.target.value)} />
          </div>
          <div>
            <label className="mb-1 block text-xs text-muted-foreground">Period to</label>
            <Input type="date" value={windowTo} onChange={(e) => setWindowTo(e.target.value)} />
          </div>
          <div>
            <label className="mb-1 block text-xs text-muted-foreground">Match</label>
            <select
              value={match}
              onChange={(e) => setMatch(e.target.value as "exact" | "overlap")}
              className="h-9 w-full rounded-md border bg-background px-3 text-sm"
            >
              <option value="overlap">Overlapping periods</option>
              <option value="exact">Exact period</option>
            </select>
          </div>
          <div className="flex items-end">
            <p className="text-xs text-muted-foreground">
              {match === "exact"
                ? "Only the period with exactly these endpoints."
                : "Every period intersecting these dates."}
            </p>
          </div>
        </CardContent>
      </Card>

      <div className="flex flex-wrap gap-2">
        {VIEWS.map((item) => (
          <Button
            key={item.key}
            variant={view === item.key ? "default" : "outline"}
            size="sm"
            onClick={() => setView(item.key)}
          >
            {item.label}
          </Button>
        ))}
      </div>

      {error ? (
        <Card className="border-destructive/40">
          <CardContent className="flex items-center gap-3 py-6 text-destructive">
            <TriangleAlert className="size-5" />
            {error}
          </CardContent>
        </Card>
      ) : null}

      {loading && windows.length === 0 ? (
        <div className="grid gap-4 md:grid-cols-2">
          {[0, 1].map((i) => (
            <Card key={i} className="h-44 animate-pulse bg-muted/40" />
          ))}
        </div>
      ) : null}

      {!loading && windows.length === 0 && !error ? (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            No broker summary periods found. Run{" "}
            <code className="font-mono text-xs">php artisan stockbit:scrape</code> and then{" "}
            <code className="font-mono text-xs">php artisan broker-summary:rebuild</code>.
          </CardContent>
        </Card>
      ) : null}

      {view === "overview" && windows.length > 0 ? (
        <>
          <div className="grid gap-4 lg:grid-cols-2">
            {windows.map((window) => (
              <WindowCard
                key={window.id}
                window={window}
                selected={selected?.id === window.id}
                onSelect={setSelected}
              />
            ))}
          </div>

          {selected ? (
            <Card>
              <CardHeader>
                <CardTitle>Top net brokers</CardTitle>
                <CardDescription>
                  {selected.symbol} · {formatWindow(selected.from_date, selected.to_date)} — figures
                  are totals for the whole period.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <CoverageNote coverage={selected.coverage} />
                <EntriesTable
                  entries={[...(selected.buyers ?? []), ...(selected.sellers ?? [])]}
                  showWindow={false}
                />
              </CardContent>
            </Card>
          ) : null}
        </>
      ) : null}

      {view === "entries" ? (
        <>
          <Card>
            <CardContent className="grid gap-3 py-5 sm:grid-cols-3">
              <div>
                <label className="mb-1 block text-xs text-muted-foreground">Broker</label>
                <Input
                  value={broker}
                  onChange={(e) => {
                    setBroker(e.target.value)
                    setEntriesPage(1)
                  }}
                  placeholder="ZP"
                />
              </div>
              <div>
                <label className="mb-1 block text-xs text-muted-foreground">Side</label>
                <select
                  value={side}
                  onChange={(e) => {
                    setSide(e.target.value as BrokerSide | "")
                    setEntriesPage(1)
                  }}
                  className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                >
                  <option value="">Both</option>
                  <option value="buy">Net buyers</option>
                  <option value="sell">Net sellers</option>
                </select>
              </div>
            </CardContent>
          </Card>

          <EntriesTable entries={entries} />

          {entriesMeta && entriesMeta.last_page > 1 ? (
            <div className="flex items-center justify-between gap-3">
              <p className="text-sm text-muted-foreground">
                Page {entriesMeta.current_page} of {entriesMeta.last_page} · {entriesMeta.total}{" "}
                entries
              </p>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={entriesMeta.current_page <= 1 || loading}
                  onClick={() => setEntriesPage((p) => Math.max(1, p - 1))}
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={entriesMeta.current_page >= entriesMeta.last_page || loading}
                  onClick={() => setEntriesPage((p) => p + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          ) : null}
        </>
      ) : null}

      {view === "detector" ? (
        selected?.bandar_detector ? (
          <>
            <Card>
              <CardContent className="flex flex-wrap items-center justify-between gap-3 py-5">
                <div className="space-y-1">
                  <p className="font-semibold">{selected.symbol}</p>
                  <WindowBadge window={selected} />
                </div>
                <CoverageNote coverage={selected.coverage} />
              </CardContent>
            </Card>
            <DetectorPanel window={selected} detector={selected.bandar_detector} />
          </>
        ) : (
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">
              {selected
                ? "This period has no bandar detector data."
                : "Select a period on the Overview tab first."}
            </CardContent>
          </Card>
        )
      ) : null}
    </div>
  )
}
