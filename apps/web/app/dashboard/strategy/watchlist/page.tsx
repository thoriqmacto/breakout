"use client"

import Link from "next/link"
import { useCallback, useEffect, useMemo, useState } from "react"
import { Loader2, RefreshCcw } from "lucide-react"

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
import { formatIdr } from "@/lib/currency"
import {
  fetchWatchlist,
  type WatchlistPayload,
  type WatchlistRow,
  type WatchlistTopBroker,
} from "@/lib/strategy-client"

/**
 * The range the broker figures cover.
 *
 * A broker summary is an aggregate over from..to, so the same net value can be
 * one session or three months of accumulated flow. Labelling it is the whole
 * point: unlabelled, the two read identically. Older rows carry no range and
 * simply get no label.
 */
function brokerRangeLabel(brokers: WatchlistTopBroker[]): string | null {
  const first = brokers[0]

  if (!first?.from || !first?.to) {
    return null
  }

  return first.from === first.to ? first.from : `${first.from} → ${first.to}`
}

type SortKey =
  | "score_total"
  | "score_bas"
  | "score_bcs"
  | "risk_reward"
  | "vol_ratio_20"
  | "symbol"

const SORT_OPTIONS: { value: SortKey; label: string }[] = [
  { value: "score_total", label: "Total score" },
  { value: "score_bas", label: "BAS" },
  { value: "score_bcs", label: "BCS" },
  { value: "risk_reward", label: "Risk/Reward" },
  { value: "vol_ratio_20", label: "Volume ratio" },
  { value: "symbol", label: "Symbol" },
]

function sortRows(rows: WatchlistRow[], key: SortKey): WatchlistRow[] {
  return [...rows].sort((a, b) => {
    if (key === "symbol") {
      return a.symbol.localeCompare(b.symbol)
    }
    const av = (a[key] as number | null) ?? -Infinity
    const bv = (b[key] as number | null) ?? -Infinity
    return bv - av
  })
}

const fmtNum = (value: number | null | undefined, digits = 2) => {
  if (value === null || value === undefined) return "—"
  return value.toLocaleString("en-US", {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  })
}

const passClass = (pass: boolean) =>
  pass ? "text-emerald-600" : "text-rose-500"

const scoreClass = (score: number) => {
  if (score >= 75) return "text-emerald-600"
  if (score >= 50) return "text-amber-600"
  return "text-muted-foreground"
}

export default function StrategyWatchlistPage() {
  const { accessToken } = useAuth()
  const [data, setData] = useState<WatchlistPayload | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [date, setDate] = useState("")
  const [version, setVersion] = useState("v1")
  const [symbol, setSymbol] = useState("")
  const [minScore, setMinScore] = useState("")
  const [sortKey, setSortKey] = useState<SortKey>("score_total")
  const [expanded, setExpanded] = useState<Set<number>>(new Set())

  const refresh = useCallback(
    async (silent = false) => {
      if (!accessToken) return
      if (!silent) setLoading(true)
      setError(null)
      try {
        const payload = await fetchWatchlist(accessToken, {
          date: date || undefined,
          version: version || undefined,
          symbol: symbol || undefined,
          minScore: minScore.trim() === "" ? undefined : Number.parseFloat(minScore),
        })
        setData(payload)
      } catch (e) {
        setError(e instanceof Error ? e.message : "Unable to load watchlist.")
      } finally {
        setLoading(false)
      }
    },
    [accessToken, date, version, symbol, minScore],
  )

  useEffect(() => {
    void refresh(true)
  }, [refresh])

  const sortedRows = useMemo(
    () => (data ? sortRows(data.rows, sortKey) : []),
    [data, sortKey],
  )

  const toggleExpanded = (id: number) => {
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Strategy watchlist</CardTitle>
          <CardDescription>
            The raw persisted scores: broker accumulation, breakout confirmation, liquidity and
            risk/reward, measured at the signal session&apos;s close. Research only — no profit
            guarantee, no execution.
          </CardDescription>
          <CardDescription>
            These same scores, with a next-session entry trigger, a stop, a target and the
            risk/reward recomputed at the trigger rather than at the close, are on{" "}
            <Link href="/dashboard/execution" className="font-medium underline">
              Execution
            </Link>
            . A setup can clear its minimum here and fail it there, which is the difference that
            matters.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <form
            className="grid gap-3 md:grid-cols-6"
            onSubmit={(e) => {
              e.preventDefault()
              void refresh()
            }}
          >
            <label className="flex flex-col gap-1 text-xs md:col-span-1">
              <span className="text-muted-foreground">Scan date</span>
              <Input
                type="date"
                value={date}
                onChange={(e) => setDate(e.target.value)}
                placeholder="latest"
              />
            </label>
            <label className="flex flex-col gap-1 text-xs md:col-span-1">
              <span className="text-muted-foreground">Version</span>
              <Input
                type="text"
                value={version}
                onChange={(e) => setVersion(e.target.value)}
                placeholder="v1"
              />
            </label>
            <label className="flex flex-col gap-1 text-xs md:col-span-1">
              <span className="text-muted-foreground">Symbol</span>
              <Input
                type="text"
                value={symbol}
                onChange={(e) => setSymbol(e.target.value.toUpperCase())}
                placeholder="all"
              />
            </label>
            <label className="flex flex-col gap-1 text-xs md:col-span-1">
              <span className="text-muted-foreground">Min score</span>
              <Input
                type="number"
                inputMode="decimal"
                step="0.1"
                value={minScore}
                onChange={(e) => setMinScore(e.target.value)}
                placeholder="0"
              />
            </label>
            <label className="flex flex-col gap-1 text-xs md:col-span-1">
              <span className="text-muted-foreground">Sort by</span>
              <select
                className="h-9 rounded-md border bg-background px-2 text-sm"
                value={sortKey}
                onChange={(e) => setSortKey(e.target.value as SortKey)}
              >
                {SORT_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <div className="flex items-end gap-2">
              <Button type="submit" disabled={loading} className="w-full md:w-auto">
                {loading ? (
                  <Loader2 className="size-4 animate-spin" aria-hidden />
                ) : (
                  <RefreshCcw className="size-4" aria-hidden />
                )}
                <span className="ml-2">Apply</span>
              </Button>
            </div>
          </form>

          {error ? (
            <p className="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
              {error}
            </p>
          ) : null}

          <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm text-muted-foreground">
            <p>
              Scan date:{" "}
              <span className="font-medium text-foreground">{data?.scan_date ?? "—"}</span> ·
              Version:{" "}
              <span className="font-medium text-foreground">{data?.version ?? version}</span> ·
              Rows: <span className="font-medium text-foreground">{sortedRows.length}</span>
            </p>
            {data?.disclaimer ? (
              <p className="rounded bg-muted/50 px-3 py-1 text-xs">{data.disclaimer}</p>
            ) : null}
          </div>

          {sortedRows.length === 0 ? (
            <p className="rounded border border-dashed bg-muted/30 px-3 py-10 text-center text-sm text-muted-foreground">
              {loading
                ? "Loading watchlist…"
                : "No rows for the selected filters. Try clearing min score or running strategy:rank-watchlist for this date."}
            </p>
          ) : (
            <div className="overflow-x-auto rounded border">
              <table className="min-w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2 text-left">Symbol</th>
                    <th className="px-3 py-2 text-right">Score</th>
                    <th className="px-3 py-2 text-right">BAS</th>
                    <th className="px-3 py-2 text-right">BCS</th>
                    <th className="px-3 py-2 text-center">LF</th>
                    <th className="px-3 py-2 text-center">RRF</th>
                    <th className="px-3 py-2 text-right">Close</th>
                    <th className="px-3 py-2 text-right">Stop</th>
                    <th className="px-3 py-2 text-right">Target</th>
                    <th className="px-3 py-2 text-right">R/R</th>
                    <th className="px-3 py-2 text-right">VolR</th>
                    <th className="px-3 py-2 text-center">Brk20</th>
                    <th className="px-3 py-2 text-right">Why</th>
                  </tr>
                </thead>
                <tbody>
                  {sortedRows.map((row) => {
                    const isOpen = expanded.has(row.id)
                    return (
                      <RowGroup
                        key={row.id}
                        row={row}
                        isOpen={isOpen}
                        onToggle={() => toggleExpanded(row.id)}
                      />
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

type RowGroupProps = {
  row: WatchlistRow
  isOpen: boolean
  onToggle: () => void
}

function RowGroup({ row, isOpen, onToggle }: RowGroupProps) {
  return (
    <>
      <tr className="border-t">
        <td className="px-3 py-2 font-medium">
          {row.symbol}
          {row.asset?.sector ? (
            <span className="ml-2 text-xs text-muted-foreground">{row.asset.sector}</span>
          ) : null}
        </td>
        <td className={`px-3 py-2 text-right font-semibold ${scoreClass(row.score_total)}`}>
          {fmtNum(row.score_total, 1)}
        </td>
        <td className="px-3 py-2 text-right">{fmtNum(row.score_bas, 1)}</td>
        <td className="px-3 py-2 text-right">{fmtNum(row.score_bcs, 1)}</td>
        <td className={`px-3 py-2 text-center font-semibold ${passClass(row.lf_pass)}`}>
          {row.lf_pass ? "✓" : "·"}
        </td>
        <td className={`px-3 py-2 text-center font-semibold ${passClass(row.rrf_pass)}`}>
          {row.rrf_pass ? "✓" : "·"}
        </td>
        <td className="px-3 py-2 text-right">{row.close === null ? "—" : formatIdr(row.close)}</td>
        <td className="px-3 py-2 text-right">
          {row.invalidation_level === null ? "—" : formatIdr(row.invalidation_level)}
        </td>
        <td className="px-3 py-2 text-right">
          {row.take_profit === null ? "—" : formatIdr(row.take_profit)}
        </td>
        <td className="px-3 py-2 text-right">{fmtNum(row.risk_reward)}</td>
        <td className="px-3 py-2 text-right">{fmtNum(row.vol_ratio_20)}</td>
        <td className="px-3 py-2 text-center">{row.breakout20 ? "✓" : "·"}</td>
        <td className="px-3 py-2 text-right">
          <Button type="button" variant="ghost" size="sm" onClick={onToggle}>
            {isOpen ? "Hide" : "Show"}
          </Button>
        </td>
      </tr>
      {isOpen ? (
        <tr className="border-t bg-muted/20">
          <td colSpan={13} className="px-3 py-3 text-xs">
            <div className="grid gap-3 md:grid-cols-3">
              <div>
                <p className="font-semibold text-foreground">Reasons</p>
                <ul className="mt-1 list-disc space-y-1 pl-5 text-muted-foreground">
                  {row.reasons.length === 0 ? (
                    <li>—</li>
                  ) : (
                    row.reasons.map((reason, idx) => <li key={idx}>{reason}</li>)
                  )}
                </ul>
              </div>
              <div>
                <p className="font-semibold text-foreground">Top brokers</p>
                {brokerRangeLabel(row.top_brokers) ? (
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {brokerRangeLabel(row.top_brokers)}
                  </p>
                ) : null}
                <ul className="mt-1 space-y-1 text-muted-foreground">
                  {row.top_brokers.length === 0 ? (
                    <li>—</li>
                  ) : (
                    row.top_brokers.map((broker, idx) => (
                      <li key={`${broker.broker}-${idx}`} className="flex justify-between gap-3">
                        <span className="font-medium text-foreground">{broker.broker}</span>
                        <span className="tabular-nums">{formatIdr(broker.net_value)}</span>
                      </li>
                    ))
                  )}
                </ul>
              </div>
              <div>
                <p className="font-semibold text-foreground">Risk notes</p>
                <p className="mt-1 text-muted-foreground">{row.risk_notes ?? "—"}</p>
              </div>
            </div>
          </td>
        </tr>
      ) : null}
    </>
  )
}
