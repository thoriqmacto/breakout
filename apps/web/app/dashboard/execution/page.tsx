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

const STATUS_STYLES: Record<ExecutionStatus, string> = {
  WATCH: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200",
  ARMED: "bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200",
  TRIGGERED: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200",
  READY: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200",
  NO_CHASE: "bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200",
  HOLD: "bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200",
  TRAILING: "bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200",
  EXIT: "bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200",
  AVOID: "bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200",
  STALE: "bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
  STALE_DATA: "bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
}

/**
 * What each status means, in the user's terms. Shown next to the counts so the
 * vocabulary does not have to be learned from documentation.
 */
const STATUS_HELP: Record<ExecutionStatus, string> = {
  WATCH: "Worth following. The price setup is not ready.",
  ARMED: "Accumulating and within one ATR of its breakout level.",
  TRIGGERED: "Breakout confirmed. Actionable next session inside the entry zone.",
  READY: "Meets every v1 rule for the next session, measured at the entry trigger.",
  NO_CHASE: "The breakout was real and price has run past the entry zone.",
  HOLD: "Held, below the trailing activation level.",
  TRAILING: "Held above +5%: the trailing stop is live and can only rise.",
  EXIT: "An exit condition has been met on an open position.",
  AVOID: "Disqualified: distribution, an invalid setup, or risk beyond the ceiling.",
  STALE: "The signal is not from the latest completed session.",
  STALE_DATA: "The signal is current; its broker or price inputs are not.",
}

const REGIME_STYLES: Record<string, string> = {
  STRONG_ACCUMULATION: "text-emerald-600 dark:text-emerald-400 font-semibold",
  ACCUMULATION: "text-emerald-600 dark:text-emerald-400",
  NEUTRAL: "text-muted-foreground",
  DISTRIBUTION: "text-rose-600 dark:text-rose-400",
  STRONG_DISTRIBUTION: "text-rose-600 dark:text-rose-400 font-semibold",
}

const REGIME_SHORT: Record<string, string> = {
  STRONG_ACCUMULATION: "ACC++",
  ACCUMULATION: "ACC",
  NEUTRAL: "NEU",
  DISTRIBUTION: "DIST",
  STRONG_DISTRIBUTION: "DIST--",
}

/**
 * One window's direction as a single glyph, so four windows fit in a column
 * and the persistence pattern is readable at a glance rather than by
 * comparing four decimals.
 */
function FlowArrow({ direction, title }: { direction: number | undefined; title: string }) {
  if (direction === undefined) {
    return (
      <span className="text-muted-foreground" title={`${title}: no window`}>
        ·
      </span>
    )
  }

  if (direction > 0) {
    return (
      <span className="text-emerald-600 dark:text-emerald-400" title={`${title}: accumulating`}>
        ▲
      </span>
    )
  }

  if (direction < 0) {
    return (
      <span className="text-rose-600 dark:text-rose-400" title={`${title}: distributing`}>
        ▼
      </span>
    )
  }

  return (
    <span className="text-muted-foreground" title={`${title}: flat`}>
      –
    </span>
  )
}

function BrokerFlowStrip({ broker }: { broker: ExecutionCandidate["broker"] }) {
  return (
    <span className="inline-flex gap-1 font-mono text-xs">
      {[3, 5, 10, 20].map((days) => (
        <FlowArrow
          key={days}
          direction={broker[`flow_${days}d` as `flow_${number}d`]?.direction}
          title={`${days}D`}
        />
      ))}
    </span>
  )
}

/**
 * The probability, or an honest refusal to give one.
 *
 * A hit rate over eleven trades rendered to one decimal place invites a
 * decision it cannot support, so below the minimum sample the cell says so
 * instead of showing a number.
 */
function ProbabilityCell({ outcome }: { outcome: ExecutionCandidate["historical_outcome"] }) {
  if (outcome.status !== "OK" || outcome.probability_hit_5_before_stop === null) {
    return (
      <span
        className="text-muted-foreground text-xs"
        title={`${outcome.sample_size} comparable setup(s); ${outcome.minimum_sample} needed before a rate is shown.`}
      >
        n/a
      </span>
    )
  }

  return (
    <span title={`${outcome.bucket_label} · ${outcome.match} match`}>
      {(outcome.probability_hit_5_before_stop * 100).toFixed(1)}%
    </span>
  )
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

function DetailList({ title, items }: { title: string; items: string[] }) {
  if (items.length === 0) return null

  return (
    <div>
      <p className="pt-2 font-semibold">{title}</p>
      <ul className="text-muted-foreground list-disc space-y-0.5 pl-4">
        {items.map((item, index) => (
          <li key={`${title}-${index}`}>{item}</li>
        ))}
      </ul>
    </div>
  )
}

function CandidateDetail({ row }: { row: ExecutionCandidate }) {
  const plan = row.execution_plan
  const profit = row.profit_management
  const position = profit.position
  const history = row.historical_outcome
  const setup = row.price_setup

  return (
    <div className="bg-muted/40 space-y-4 px-4 py-4 text-xs">
      <div className="grid gap-4 md:grid-cols-4">
        <div className="space-y-1">
          <p className="font-semibold">Why this action</p>
          <ul className="text-muted-foreground list-disc space-y-0.5 pl-4">
            {row.action_reasons.map((reason, index) => (
              <li key={`action-${index}`}>{reason}</li>
            ))}
          </ul>
          <DetailList title="Broker" items={row.reasons_v2.broker} />
        </div>

        <div className="space-y-1">
          <DetailList title="Price" items={row.reasons_v2.price} />
          <DetailList title="Risk" items={row.reasons_v2.risk} />
        </div>

        <div className="space-y-1">
          <DetailList title="History" items={row.reasons_v2.history} />
          {history.status === "OK" ? (
            <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5 pt-2">
              <dt>Sample</dt>
              <dd className="text-foreground">{history.sample_size}</dd>
              <dt>Median days to +5%</dt>
              <dd className="text-foreground">{history.median_days_to_5 ?? "—"}</dd>
              <dt>Median MAE</dt>
              <dd className="text-foreground">{fmt(history.median_mae_pct)}%</dd>
              <dt>Median MFE</dt>
              <dd className="text-foreground">{fmt(history.median_mfe_pct)}%</dd>
              <dt>Median managed return</dt>
              <dd className="text-foreground">{fmt(history.median_trailing_exit_return_pct)}%</dd>
              <dt>Expectancy</dt>
              <dd className="text-foreground">{fmt(history.expectancy_pct)}%</dd>
              <dt>Profit factor</dt>
              <dd className="text-foreground">{fmt(history.profit_factor)}</dd>
            </dl>
          ) : (
            <p className="text-muted-foreground pt-2">
              INSUFFICIENT_SAMPLE — {history.sample_size} comparable setup(s), {history.minimum_sample}{" "}
              needed. No rate is shown rather than one the sample cannot support.
            </p>
          )}
        </div>

        <div className="space-y-1">
          <p className="font-semibold">Score components</p>
          <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5">
            {Object.entries(row.score_components).map(([name, component]) => (
              <Fragment key={name}>
                <dt title={component.reason}>{name.replace(/_/g, " ")}</dt>
                <dd className="text-foreground">
                  {fmt(component.value, 0)} × {component.weight.toFixed(2)} ={" "}
                  {fmt(component.contribution, 1)}
                </dd>
              </Fragment>
            ))}
          </dl>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-4">
        <div className="space-y-1">
          <p className="font-semibold">Setup</p>
          <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5">
            <dt>Breakout 20 / 55</dt>
            <dd className="text-foreground">
              {setup.breakout20 ? "✓" : "—"} / {setup.breakout55 ? "✓" : "—"}
            </dd>
            <dt>Distance to level</dt>
            <dd className="text-foreground">
              {fmt(setup.distance_to_breakout_atr)} ATR ({fmt(setup.distance_to_breakout_pct)}%)
            </dd>
            <dt>EMA20 / EMA50</dt>
            <dd className="text-foreground">
              {fmt(setup.ema20, 0)} / {fmt(setup.ema50, 0)}
            </dd>
            <dt>Close position</dt>
            <dd className="text-foreground">{fmt(setup.close_position, 2)}</dd>
            <dt>Compression</dt>
            <dd className="text-foreground">{setup.compression === null ? "—" : setup.compression ? "✓" : "—"}</dd>
            <dt>Gap</dt>
            <dd className="text-foreground">{fmt(setup.gap_pct)}%</dd>
            <dt>Swing low 20</dt>
            <dd className="text-foreground">{fmt(setup.swing_low20, 0)}</dd>
          </dl>
        </div>

        <div className="space-y-1">
          <p className="font-semibold">Plan</p>
          <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5">
            <dt>Breakout level</dt>
            <dd className="text-foreground">{fmt(plan.breakout_level, 0)}</dd>
            <dt>Entry zone</dt>
            <dd className="text-foreground">
              {fmt(plan.entry_zone_low, 0)} – {fmt(plan.entry_zone_high, 0)}
            </dd>
            <dt>Zone state</dt>
            <dd className="text-foreground">{plan.entry_zone_state}</dd>
            <dt>Judged against</dt>
            <dd className="text-foreground">
              {fmt(plan.reference_price, 0)} ({plan.reference_source.replace(/_/g, " ")})
            </dd>
            <dt>Initial stop</dt>
            <dd className="text-foreground">{fmt(plan.initial_stop, 0)}</dd>
            <dt>Stop from</dt>
            <dd className="text-foreground">{plan.initial_stop_source ?? "—"}</dd>
            <dt>Initial risk</dt>
            <dd className="text-foreground">
              {fmt(plan.initial_risk_pct)}% of {fmt(plan.max_initial_risk_pct)}% ceiling
            </dd>
          </dl>
          {plan.notes.length > 0 ? (
            <p className="pt-1 text-amber-600">{plan.notes.join(" · ")}</p>
          ) : null}
        </div>

        <div className="space-y-1">
          <p className="font-semibold">Profit management</p>
          <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5">
            <dt>+{fmt(profit.activation_gain_pct, 0)}% activation</dt>
            <dd className="text-foreground">{fmt(profit.activation_price, 0)}</dd>
            <dt>+{fmt(profit.minimum_locked_profit_pct, 0)}% floor</dt>
            <dd className="text-foreground">{fmt(profit.profit_floor_price, 0)}</dd>
            <dt>Trail distance</dt>
            <dd className="text-foreground">{fmt(profit.trailing_distance_pct, 1)}%</dd>
            <dt>Round-trip cost</dt>
            <dd className="text-foreground">{fmt(profit.round_trip_cost_pct)}%</dd>
          </dl>
          <p className="text-muted-foreground pt-1">
            The floor is a price level, not a guaranteed return: gaps, slippage and fees all come
            off whatever is realised.
          </p>
          {position ? (
            <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5 pt-2">
              <dt>Entry (portfolio)</dt>
              <dd className="text-foreground">{fmt(position.entry_price, 0)}</dd>
              <dt>Opened</dt>
              <dd className="text-foreground">{position.opened_at ?? "—"}</dd>
              <dt>Current gain</dt>
              <dd className="text-foreground">{fmt(position.current_gain_pct)}%</dd>
              <dt>Highest since entry</dt>
              <dd className="text-foreground">{fmt(position.highest_price_since_entry, 0)}</dd>
              <dt>Trailing</dt>
              <dd className="text-foreground">
                {position.trailing_active
                  ? `active since ${position.trailing_activated_at ?? "—"}`
                  : `${fmt(position.distance_to_activation_pct)}% to activation`}
              </dd>
              <dt>Effective stop</dt>
              <dd className="text-foreground">
                {fmt(position.effective_stop_price, 0)} ({fmt(position.locked_profit_pct)}%)
              </dd>
            </dl>
          ) : null}
        </div>

        <div className="space-y-1">
          <p className="font-semibold">Data quality</p>
          <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-0.5">
            <dt>Price data</dt>
            <dd className="text-foreground">
              {row.data_quality.price_date} {row.data_quality.ohlcv_current ? "✓" : "⚠"}
            </dd>
            <dt>Broker data</dt>
            <dd className="text-foreground">
              {row.data_quality.broker_window_to ?? "—"} {row.data_quality.broker_current ? "✓" : "⚠"}
            </dd>
            <dt>Broker lag</dt>
            <dd className="text-foreground">
              {row.data_quality.broker_lag_days ?? "—"} / {row.data_quality.max_broker_lag_days} allowed
            </dd>
            <dt>Setup bucket</dt>
            <dd className="text-foreground">{row.setup_bucket_label ?? "—"}</dd>
            <dt>Profile</dt>
            <dd className="text-foreground">{row.strategy_version}</dd>
          </dl>
          {row.data_quality.reasons.length > 0 ? (
            <p className="pt-1 text-amber-600">{row.data_quality.reasons.join(" · ")}</p>
          ) : null}
          {row.warnings.length > 0 ? (
            <p className="pt-1 text-amber-600">{row.warnings.join(" · ")}</p>
          ) : null}
        </div>
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
  const [statuses, setStatuses] = useState<ExecutionStatus[]>(["TRIGGERED", "ARMED", "HOLD", "TRAILING"])
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
          <div>
            <p className="text-muted-foreground text-xs">Strategy profile</p>
            <p className="font-medium">{payload?.strategy_profile.version ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Profit lifecycle</p>
            <p className="font-medium">
              +{fmt(payload?.strategy_profile.trail_activation_gain_pct, 0)}% activates ·{" "}
              {fmt(payload?.strategy_profile.trailing_distance_pct, 1)}% trail ·{" "}
              +{fmt(payload?.strategy_profile.minimum_locked_profit_pct, 0)}% floor
            </p>
          </div>
          <div>
            <p className="text-muted-foreground text-xs">Costs assumed</p>
            <p className="font-medium">
              {fmt(payload?.costs.buy_fee_pct)}% buy · {fmt(payload?.costs.sell_fee_pct)}% sell ·{" "}
              {fmt(payload?.costs.slippage_pct)}% slippage
            </p>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
            <table className="w-full min-w-[1500px] text-sm">
              <thead className="text-muted-foreground border-b text-xs">
                <tr>
                  <th className="w-8" />
                  <th className="px-3 py-2 text-left">Exec</th>
                  <th className="px-3 py-2 text-left">Symbol</th>
                  <th className="px-3 py-2 text-left">Status</th>
                  <th className="px-3 py-2 text-left">Action</th>
                  <th className="px-3 py-2 text-right">Score</th>
                  <th className="px-3 py-2 text-left" title="Broker regime from the 5/10/20-day windows">
                    Regime
                  </th>
                  <th className="px-3 py-2 text-center" title="3D / 5D / 10D / 20D broker flow direction">
                    Flow
                  </th>
                  <th className="px-3 py-2 text-right" title="Fraction of broker windows accumulating">
                    Persist
                  </th>
                  <th className="px-3 py-2 text-center" title="20-session breakout confirmed">
                    B/O
                  </th>
                  <th className="px-3 py-2 text-right">Vol×</th>
                  <th className="px-3 py-2 text-right">Close</th>
                  <th className="px-3 py-2 text-right">Trigger</th>
                  <th className="px-3 py-2 text-right" title="Entry zone: trigger to trigger + ATR extension">
                    Zone
                  </th>
                  <th className="px-3 py-2 text-right">Stop</th>
                  <th className="px-3 py-2 text-right" title="Initial risk as a percentage of the trigger">
                    Risk%
                  </th>
                  <th className="px-3 py-2 text-right" title="Trailing activation price (+5%)">
                    +5%
                  </th>
                  <th className="px-3 py-2 text-right" title="Minimum locked-profit floor (+3%)">
                    Floor
                  </th>
                  <th
                    className="px-3 py-2 text-right"
                    title="Share of comparable historical setups that reached +5% before their initial stop"
                  >
                    P(+5%)
                  </th>
                  <th className="px-3 py-2 text-right" title="Comparable sample size">
                    n
                  </th>
                  <th className="px-3 py-2 text-right">Held</th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={21} className="text-muted-foreground px-3 py-8 text-center">
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
                        <StatusBadge status={row.lifecycle_status} />
                      </td>
                      <td className="px-3 py-2">
                        <span
                          className="text-xs font-medium"
                          title={row.action_reasons.join(" · ")}
                        >
                          {row.action.replace(/_/g, " ")}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-right font-medium">
                        <span title={`v1 score ${fmt(row.execution_score, 1)}`}>
                          {fmt(row.execution_score_v2, 1)}
                        </span>
                      </td>
                      <td className={`px-3 py-2 text-xs ${REGIME_STYLES[row.broker.regime] ?? ""}`}>
                        <span title={row.broker.reasons.join(" · ")}>
                          {REGIME_SHORT[row.broker.regime] ?? row.broker.regime}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-center">
                        <BrokerFlowStrip broker={row.broker} />
                      </td>
                      <td className="px-3 py-2 text-right">
                        <span
                          title={`${row.broker.positive_windows} of ${row.broker.available_windows} windows accumulating`}
                        >
                          {row.broker.persistence_ratio === null
                            ? "—"
                            : `${row.broker.positive_windows}/${row.broker.available_windows}`}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-center">
                        <span title={row.price_setup.breakout55 ? "55-session breakout" : undefined}>
                          {row.price_setup.breakout55 ? "✓✓" : row.price_setup.breakout20 ? "✓" : "—"}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-right">{fmt(row.price_setup.vol_ratio_20)}</td>
                      <td className="px-3 py-2 text-right">{fmt(row.signal_close, 0)}</td>
                      <td className="px-3 py-2 text-right font-medium">
                        {fmt(row.execution_plan.trigger_price, 0)}
                      </td>
                      <td className="px-3 py-2 text-right">
                        <span title={row.execution_plan.entry_zone_reason}>
                          {fmt(row.execution_plan.entry_zone_high, 0)}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-right">{fmt(row.execution_plan.initial_stop, 0)}</td>
                      <td className="px-3 py-2 text-right">
                        <span
                          className={
                            row.execution_plan.valid ? undefined : "text-rose-600 dark:text-rose-400"
                          }
                          title={row.execution_plan.rejected_reason ?? undefined}
                        >
                          {fmt(row.execution_plan.initial_risk_pct)}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-right">
                        {fmt(row.profit_management.activation_price, 0)}
                      </td>
                      <td className="px-3 py-2 text-right">
                        {fmt(row.profit_management.profit_floor_price, 0)}
                      </td>
                      <td className="px-3 py-2 text-right">
                        <ProbabilityCell outcome={row.historical_outcome} />
                      </td>
                      <td className="text-muted-foreground px-3 py-2 text-right text-xs">
                        {row.historical_outcome.sample_size}
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
                        <td colSpan={21} className="p-0">
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

      <div className="text-muted-foreground space-y-1 text-xs">
        {payload?.disclaimer ? <p>{payload.disclaimer}</p> : null}
        {payload?.outcome_disclaimer ? <p>{payload.outcome_disclaimer}</p> : null}
        <p>
          Daily bars cannot say whether a session&apos;s high or its stop came first. Historical
          outcomes resolve that ambiguity against the trade
          {payload?.strategy_profile.intraday_assumption
            ? ` (${payload.strategy_profile.intraday_assumption})`
            : null}
          , so the reported rates are conservative rather than flattering.
        </p>
      </div>
    </div>
  )
}
