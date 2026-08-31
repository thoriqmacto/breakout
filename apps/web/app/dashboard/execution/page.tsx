"use client"

import Link from "next/link"
import { Fragment, useCallback, useEffect, useMemo, useState } from "react"
import { ChevronDown, ChevronRight, Loader2, RefreshCcw } from "lucide-react"

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
  EXECUTION_STATUSES,
  fetchExecutionCandidates,
  type ExecutionCandidate,
  type ExecutionPayload,
  type ExecutionStatus,
} from "@/lib/execution-client"
import {
  fetchPortfolioSummary,
  fetchPortfolios,
  type PortfolioRecord,
  type PortfolioSummaryPayload,
} from "@/lib/portfolio-client"

const fmt = (value: number | null | undefined, digits = 2) =>
  value === null || value === undefined
    ? "—"
    : value.toLocaleString("en-US", { minimumFractionDigits: digits, maximumFractionDigits: digits })

const fmtPct = (value: number | null | undefined, digits = 1) =>
  value === null || value === undefined ? "—" : `${(value * 100).toFixed(digits)}%`

const STATUS_STYLES: Record<ExecutionStatus, string> = {
  READY: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200",
  WATCH: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200",
  AVOID: "bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200",
  STALE: "bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
}

/**
 * What each status means, in the user's terms. Shown next to the counts so the
 * vocabulary does not have to be learned from documentation.
 */
const STATUS_HELP: Record<ExecutionStatus, string> = {
  READY: "Meets every stated rule for the next session, measured at the entry trigger.",
  WATCH: "Worth following, but at least one rule is not met. No entry plan.",
  AVOID: "Disqualified: distribution, an invalid long setup, or no measurable risk.",
  STALE: "The inputs are not from the latest completed session.",
}

function StatusBadge({ status }: { status: ExecutionStatus }) {
  return (
    <span className={`inline-flex rounded px-2 py-0.5 text-xs font-semibold ${STATUS_STYLES[status]}`}>
      {status}
    </span>
  )
}

function RankMove({ change }: { change: number | null }) {
  if (change === null) {
    return <span className="text-muted-foreground text-xs">new</span>
  }

  if (change === 0) {
    return <span className="text-muted-foreground text-xs">—</span>
  }

  return (
    <span className={`text-xs ${change > 0 ? "text-emerald-600" : "text-rose-500"}`}>
      {change > 0 ? `▲${change}` : `▼${Math.abs(change)}`}
    </span>
  )
}

function CandidateDetail({ row }: { row: ExecutionCandidate }) {
  return (
    <div className="bg-muted/40 grid gap-4 px-4 py-4 text-xs md:grid-cols-3">
      <div className="space-y-1">
        <p className="font-semibold">Why this status</p>
        <ul className="text-muted-foreground list-disc space-y-0.5 pl-4">
          {row.status_reasons.map((reason) => (
            <li key={reason}>{reason}</li>
          ))}
        </ul>
        {row.reasons.length > 0 ? (
          <>
            <p className="pt-2 font-semibold">Score rationale</p>
            <ul className="text-muted-foreground list-disc space-y-0.5 pl-4">
              {row.reasons.map((reason) => (
                <li key={reason}>{reason}</li>
              ))}
            </ul>
          </>
        ) : null}
      </div>

      <div className="space-y-1">
        <p className="font-semibold">Structure</p>
        <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5">
          <dt>Structural rank</dt>
          <dd className="text-foreground">{row.structural_rank ?? "—"}</dd>
          <dt>ROC13</dt>
          <dd className="text-foreground">{fmt(row.roc13)}%</dd>
          <dt>Close / 20wH</dt>
          <dd className="text-foreground">{fmt(row.close_vs_high20, 4)}</dd>
          <dt>Close / 55wH</dt>
          <dd className="text-foreground">{fmt(row.close_vs_high55, 4)}</dd>
          <dt>MA50 / 100 / 150</dt>
          <dd className="text-foreground">
            {fmt(row.ma50, 0)} / {fmt(row.ma100, 0)} / {fmt(row.ma150, 0)}
          </dd>
          <dt>ATR14</dt>
          <dd className="text-foreground">{fmt(row.atr14, 0)}</dd>
          <dt>Close position</dt>
          <dd className="text-foreground">{fmt(row.close_pos, 2)}</dd>
        </dl>
      </div>

      <div className="space-y-1">
        <p className="font-semibold">Plan &amp; data</p>
        <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5">
          <dt>Entry rule</dt>
          <dd className="text-foreground">{row.planned_entry_reason}</dd>
          <dt>Risk per share</dt>
          <dd className="text-foreground">{fmt(row.planned_risk_per_share, 0)}</dd>
          <dt>R/R at signal close</dt>
          <dd className="text-foreground">{fmt(row.signal_close_risk_reward)}</dd>
          <dt>Price data</dt>
          <dd className="text-foreground">{row.data_freshness.price_date ?? "—"}</dd>
          <dt>Features</dt>
          <dd className="text-foreground">{row.data_freshness.feature_date ?? "—"}</dd>
          <dt>Broker window</dt>
          <dd className="text-foreground">
            {row.data_freshness.broker_window_from && row.data_freshness.broker_window_to
              ? `${row.data_freshness.broker_window_from} → ${row.data_freshness.broker_window_to}`
              : "—"}
          </dd>
          <dt>READY streak</dt>
          <dd className="text-foreground">{row.ready_streak} session(s)</dd>
        </dl>

        {row.top_brokers.length > 0 ? (
          <p className="text-muted-foreground pt-1">
            Top brokers: {row.top_brokers.map((broker) => broker.broker).join(", ")}
          </p>
        ) : null}

        {row.risk_notes ? <p className="text-muted-foreground pt-1">{row.risk_notes}</p> : null}

        {row.warnings.length > 0 ? (
          <p className="pt-1 text-amber-600">{row.warnings.join(" · ")}</p>
        ) : null}
      </div>
    </div>
  )
}

export default function ExecutionWorkspacePage() {
  const { accessToken } = useAuth()

  const [payload, setPayload] = useState<ExecutionPayload | null>(null)
  const [portfolios, setPortfolios] = useState<PortfolioRecord[]>([])
  const [portfolioSummary, setPortfolioSummary] = useState<PortfolioSummaryPayload | null>(null)
  const [portfolioId, setPortfolioId] = useState<number | undefined>(undefined)
  const [statuses, setStatuses] = useState<ExecutionStatus[]>(["READY", "WATCH"])
  const [symbol, setSymbol] = useState("")
  const [minScore, setMinScore] = useState("")
  const [minRr, setMinRr] = useState("")
  const [expanded, setExpanded] = useState<number | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!accessToken) return

    setLoading(true)
    setError(null)

    try {
      const data = await fetchExecutionCandidates(accessToken, {
        statuses: statuses.length > 0 ? statuses : undefined,
        symbols: symbol.trim() ? [symbol.trim()] : undefined,
        minScore: minScore.trim() ? Number(minScore) : undefined,
        minRr: minRr.trim() ? Number(minRr) : undefined,
        portfolioId,
      })
      setPayload(data)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Unable to load execution candidates.")
    } finally {
      setLoading(false)
    }
  }, [accessToken, statuses, symbol, minScore, minRr, portfolioId])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (!accessToken) return

    fetchPortfolios(accessToken)
      .then(setPortfolios)
      .catch(() => setPortfolios([]))
  }, [accessToken])

  // Available cash comes from the summary, never from the portfolio row's
  // cash_balance -- that column is the *base* now, and labelling it "available"
  // is exactly the confusion the accounting fix removed.
  useEffect(() => {
    if (!accessToken || portfolioId === undefined) {
      setPortfolioSummary(null)

      return
    }

    fetchPortfolioSummary(accessToken, portfolioId)
      .then(setPortfolioSummary)
      .catch(() => setPortfolioSummary(null))
  }, [accessToken, portfolioId])

  const toggleStatus = (status: ExecutionStatus) => {
    setStatuses((current) =>
      current.includes(status) ? current.filter((value) => value !== status) : [...current, status],
    )
  }

  const rows = payload?.rows ?? []
  const counts = payload?.counts ?? {}

  const selectedPortfolio = useMemo(
    () => portfolios.find((portfolio) => portfolio.id === portfolioId) ?? null,
    [portfolios, portfolioId],
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Execution</h1>
          <p className="text-muted-foreground text-sm">
            What the last completed session says may be actionable next session. Research and
            decision support — not advice.
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={() => void load()} disabled={loading}>
          {loading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCcw className="mr-2 h-4 w-4" />}
          Refresh
        </Button>
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Session</CardTitle>
          <CardDescription>
            A signal computed from a completed session cannot be traded at that session&apos;s close.
            The earliest opportunity is the next session.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 text-sm md:grid-cols-4">
          <div>
            <p className="text-muted-foreground text-xs">Signal date</p>
            <p className="font-medium">{payload?.signal_date ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Next session</p>
            <p className="font-medium">{payload?.next_trading_date ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Latest price data</p>
            <p className="font-medium">{payload?.freshness.latest_price_date ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Latest broker window</p>
            <p className="font-medium">{payload?.freshness.latest_broker_window_date ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Latest features</p>
            <p className="font-medium">{payload?.freshness.latest_feature_date ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Scoring version</p>
            <p className="font-medium">{payload?.version ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Thresholds</p>
            <p className="font-medium">
              score ≥ {fmt(payload?.thresholds.min_score, 0)} · R/R ≥ {fmt(payload?.thresholds.min_rr)}
            </p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Evaluated</p>
            <p className="font-medium">{counts.TOTAL ?? 0} candidate(s)</p>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {EXECUTION_STATUSES.map((status) => (
          <Card key={status}>
            <CardContent className="space-y-1 pt-5">
              <div className="flex items-center justify-between">
                <StatusBadge status={status} />
                <span className="text-2xl font-semibold">{counts[status] ?? 0}</span>
              </div>
              <p className="text-muted-foreground text-xs">{STATUS_HELP[status]}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Filters</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-wrap items-end gap-3">
          <div className="space-y-1">
            <p className="text-muted-foreground text-xs">Status</p>
            <div className="flex flex-wrap gap-1">
              {EXECUTION_STATUSES.map((status) => (
                <Button
                  key={status}
                  size="sm"
                  variant={statuses.includes(status) ? "default" : "outline"}
                  onClick={() => toggleStatus(status)}
                >
                  {status}
                </Button>
              ))}
            </div>
          </div>
          <div className="space-y-1">
            <p className="text-muted-foreground text-xs">Symbol</p>
            <Input
              value={symbol}
              onChange={(event) => setSymbol(event.target.value)}
              placeholder="BBCA"
              className="w-28"
            />
          </div>
          <div className="space-y-1">
            <p className="text-muted-foreground text-xs">Min score</p>
            <Input
              value={minScore}
              onChange={(event) => setMinScore(event.target.value)}
              placeholder={String(payload?.thresholds.min_score ?? 75)}
              className="w-24"
            />
          </div>
          <div className="space-y-1">
            <p className="text-muted-foreground text-xs">Min R/R</p>
            <Input
              value={minRr}
              onChange={(event) => setMinRr(event.target.value)}
              placeholder={String(payload?.thresholds.min_rr ?? 2)}
              className="w-24"
            />
          </div>
          {portfolios.length > 0 ? (
            <div className="space-y-1">
              <p className="text-muted-foreground text-xs">Portfolio</p>
              <select
                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                value={portfolioId ?? ""}
                onChange={(event) =>
                  setPortfolioId(event.target.value ? Number(event.target.value) : undefined)
                }
              >
                <option value="">None</option>
                {portfolios.map((portfolio) => (
                  <option key={portfolio.id} value={portfolio.id}>
                    {portfolio.name}
                  </option>
                ))}
              </select>
            </div>
          ) : null}
        </CardContent>
      </Card>

      {selectedPortfolio ? (
        <Card>
          <CardContent className="flex flex-wrap gap-6 pt-5 text-sm">
            <div>
              <p className="text-muted-foreground text-xs">Portfolio</p>
              <p className="font-medium">{selectedPortfolio.name}</p>
            </div>
            <div>
              <p className="text-muted-foreground text-xs">Available cash</p>
              <p className="font-medium">
                {portfolioSummary ? formatIdr(portfolioSummary.cash_balance) : "—"}
              </p>
            </div>
            <div>
              <p className="text-muted-foreground text-xs">Market value</p>
              <p className="font-medium">
                {portfolioSummary ? formatIdr(portfolioSummary.total_market_value) : "—"}
              </p>
            </div>
            <p className="text-muted-foreground self-end text-xs">
              Holdings are shown on any candidate you already own.
            </p>
          </CardContent>
        </Card>
      ) : null}

      {error ? (
        <Card>
          <CardContent className="text-destructive pt-6 text-sm">{error}</CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Candidates</CardTitle>
          <CardDescription>
            Execution rank orders by how actionable the setup is next session. Structural rank is a
            separate question — how strong the stock is relative to the universe.
          </CardDescription>
        </CardHeader>
        <CardContent className="px-0">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1100px] text-sm">
              <thead className="text-muted-foreground border-b text-xs">
                <tr>
                  <th className="w-8" />
                  <th className="px-3 py-2 text-left">Exec</th>
                  <th className="px-3 py-2 text-left">Symbol</th>
                  <th className="px-3 py-2 text-left">Status</th>
                  <th className="px-3 py-2 text-right">Score</th>
                  <th className="px-3 py-2 text-right">Struct</th>
                  <th className="px-3 py-2 text-center">Up</th>
                  <th className="px-3 py-2 text-right">PBAS</th>
                  <th className="px-3 py-2 text-right">BAS</th>
                  <th className="px-3 py-2 text-right">BCS</th>
                  <th className="px-3 py-2 text-center">B/O</th>
                  <th className="px-3 py-2 text-right">Vol×</th>
                  <th className="px-3 py-2 text-right">Close</th>
                  <th className="px-3 py-2 text-right">Trigger</th>
                  <th className="px-3 py-2 text-right">Stop</th>
                  <th className="px-3 py-2 text-right">Target</th>
                  <th className="px-3 py-2 text-right">R/R</th>
                  <th className="px-3 py-2 text-right">BAVG</th>
                  <th className="px-3 py-2 text-right">Held</th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={19} className="text-muted-foreground px-3 py-8 text-center">
                      {loading ? "Loading…" : "No candidates for the current filters."}
                    </td>
                  </tr>
                ) : null}

                {rows.map((row) => (
                  <Fragment key={row.symbol}>
                    <tr className="hover:bg-muted/40 border-b">
                      <td className="px-1">
                        <button
                          type="button"
                          aria-label={expanded === row.asset_id ? "Collapse" : "Expand"}
                          onClick={() =>
                            setExpanded((current) => (current === row.asset_id ? null : row.asset_id))
                          }
                          className="text-muted-foreground hover:text-foreground p-1"
                        >
                          {expanded === row.asset_id ? (
                            <ChevronDown className="h-4 w-4" />
                          ) : (
                            <ChevronRight className="h-4 w-4" />
                          )}
                        </button>
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex items-center gap-1">
                          <span className="font-medium">{row.execution_rank}</span>
                          <RankMove change={row.execution_rank_change_1d} />
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <Link
                          href={`/dashboard/assets/${row.asset_id}`}
                          className="font-medium hover:underline"
                        >
                          {row.symbol}
                        </Link>
                        <p className="text-muted-foreground text-xs">{row.sector ?? "—"}</p>
                      </td>
                      <td className="px-3 py-2">
                        <StatusBadge status={row.execution_status} />
                      </td>
                      <td className="px-3 py-2 text-right font-medium">{fmt(row.execution_score, 1)}</td>
                      <td className="px-3 py-2 text-right">{row.structural_rank ?? "—"}</td>
                      <td className="px-3 py-2 text-center">{row.uptrend ? "✓" : "—"}</td>
                      <td className="px-3 py-2 text-right">{row.pbas ?? "—"}</td>
                      <td className="px-3 py-2 text-right">{fmt(row.bas, 0)}</td>
                      <td className="px-3 py-2 text-right">{fmt(row.bcs, 0)}</td>
                      <td className="px-3 py-2 text-center">{row.breakout20 ? "✓" : "—"}</td>
                      <td className="px-3 py-2 text-right">{fmt(row.vol_ratio_20)}</td>
                      <td className="px-3 py-2 text-right">{fmt(row.signal_close, 0)}</td>
                      <td className="px-3 py-2 text-right font-medium">
                        {fmt(row.planned_entry_trigger, 0)}
                      </td>
                      <td className="px-3 py-2 text-right">{fmt(row.planned_stop, 0)}</td>
                      <td className="px-3 py-2 text-right">{fmt(row.planned_target, 0)}</td>
                      <td className="px-3 py-2 text-right">{fmt(row.planned_risk_reward)}</td>
                      <td className="px-3 py-2 text-right">
                        {row.bavg === null ? (
                          "—"
                        ) : (
                          <span title={`BAVG ${fmt(row.bavg, 0)}`}>
                            {fmtPct(
                              row.distance_from_bavg_pct === null
                                ? null
                                : row.distance_from_bavg_pct / 100,
                            )}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-right">
                        {row.holding ? (
                          <span title={`Avg cost ${fmt(row.holding.avg_cost, 0)}`}>
                            {fmt(row.holding.qty, 0)}
                          </span>
                        ) : (
                          "—"
                        )}
                      </td>
                    </tr>
                    {expanded === row.asset_id ? (
                      <tr>
                        <td colSpan={19} className="p-0">
                          <CandidateDetail row={row} />
                        </td>
                      </tr>
                    ) : null}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {payload?.disclaimer ? (
        <p className="text-muted-foreground text-xs">{payload.disclaimer}</p>
      ) : null}
    </div>
  )
}
