"use client"

import { useCallback, useEffect, useState } from "react"
import { Loader2, RefreshCcw } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { formatIdr } from "@/lib/currency"
import {
  fetchPortfolioSummary,
  type PortfolioSummaryPayload,
} from "@/lib/portfolio-client"

type Props = {
  accessToken: string
  portfolioId: number | null
  /**
   * Optional revision number; when callers bump this, the widget re-fetches.
   * Useful after position or cash-movement mutations elsewhere on the page.
   */
  revision?: number
}

const formatPct = (value: number | null) => {
  if (value === null || value === undefined) return "—"
  const sign = value >= 0 ? "+" : ""
  return `${sign}${value.toFixed(2)}%`
}

const plClass = (value: number) =>
  value > 0 ? "text-emerald-600" : value < 0 ? "text-rose-600" : "text-muted-foreground"

export function PortfolioSummaryCard({ accessToken, portfolioId, revision = 0 }: Props) {
  const [summary, setSummary] = useState<PortfolioSummaryPayload | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const refresh = useCallback(async () => {
    if (!portfolioId) {
      setSummary(null)
      return
    }

    setLoading(true)
    setError(null)
    try {
      const data = await fetchPortfolioSummary(accessToken, portfolioId)
      setSummary(data)
    } catch (e) {
      const message = e instanceof Error ? e.message : "Unable to load portfolio summary."
      setError(message)
      setSummary(null)
    } finally {
      setLoading(false)
    }
  }, [accessToken, portfolioId])

  useEffect(() => {
    void refresh()
  }, [refresh, revision])

  if (!portfolioId) {
    return null
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle>Portfolio summary</CardTitle>
          <CardDescription>
            Cash, holdings, and explainable P/L derived from the latest EOD prices. Research only —
            no trading execution.
          </CardDescription>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => void refresh()}
          disabled={loading}
        >
          {loading ? (
            <Loader2 className="size-4 animate-spin" aria-hidden />
          ) : (
            <RefreshCcw className="size-4" aria-hidden />
          )}
          <span className="ml-2">Refresh</span>
        </Button>
      </CardHeader>

      <CardContent className="space-y-6">
        {error ? (
          <p className="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
            {error}
          </p>
        ) : null}

        {summary ? (
          <>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
              <Stat label="Available cash" value={formatIdr(summary.cash_balance)} />
              <Stat label="Market value" value={formatIdr(summary.total_market_value)} />
              <Stat
                label="Total equity"
                value={formatIdr(summary.total_equity)}
                emphasize
              />
              <Stat
                label="Realized P/L"
                value={formatIdr(summary.realized_pl)}
                tone={summary.realized_pl}
              />
              <Stat
                label="Unrealized P/L"
                value={formatIdr(summary.unrealized_pl)}
                tone={summary.unrealized_pl}
              />
            </div>

            <details className="rounded border bg-muted/20 px-3 py-2">
              <summary className="cursor-pointer text-sm font-medium text-muted-foreground">
                How available cash is calculated
              </summary>
              <div className="mt-3 space-y-1 text-sm">
                <CashLine label="Base cash (opening)" value={summary.base_cash_balance} />
                <CashLine label="Deposits" value={summary.cash_breakdown.deposit} />
                <CashLine label="Withdrawals" value={summary.cash_breakdown.withdraw} />
                <CashLine label="Dividends" value={summary.cash_breakdown.dividend} />
                <CashLine label="Standalone fees" value={summary.cash_breakdown.fee} />
                <CashLine label="Adjustments" value={summary.cash_breakdown.adjustment} />
                <CashLine label="Trade settlement" value={summary.trade_cash_flow} />
                <div className="mt-2 flex items-center justify-between border-t pt-2 font-semibold">
                  <span>Available cash</span>
                  <span>{formatIdr(summary.cash_balance)}</span>
                </div>
                <p className="pt-2 text-xs text-muted-foreground">
                  Buying settles cash out; selling settles it back in, less the fee. Realized P/L is
                  reported separately and is never added to cash a second time — an exit&apos;s
                  proceeds already contain it.
                </p>
              </div>
            </details>

            <section>
              <h3 className="mb-2 text-sm font-medium text-muted-foreground">Holdings</h3>
              {summary.holdings.length === 0 ? (
                <p className="rounded border border-dashed bg-muted/30 px-3 py-6 text-center text-sm text-muted-foreground">
                  No open holdings yet. Add an entry position to see this fill in.
                </p>
              ) : (
                <div className="overflow-x-auto rounded border">
                  <table className="min-w-full text-sm">
                    <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
                      <tr>
                        <th className="px-3 py-2 text-left">Symbol</th>
                        <th className="px-3 py-2 text-left">Sector</th>
                        <th className="px-3 py-2 text-right">Qty</th>
                        <th className="px-3 py-2 text-right">Avg cost</th>
                        <th className="px-3 py-2 text-right">Latest close</th>
                        <th className="px-3 py-2 text-right">Market value</th>
                        <th className="px-3 py-2 text-right">Unrealized P/L</th>
                        <th className="px-3 py-2 text-right">P/L %</th>
                      </tr>
                    </thead>
                    <tbody>
                      {summary.holdings.map((h) => (
                        <tr key={h.asset_id} className="border-t">
                          <td className="px-3 py-2 font-medium">{h.symbol ?? "—"}</td>
                          <td className="px-3 py-2 text-muted-foreground">{h.sector ?? "—"}</td>
                          <td className="px-3 py-2 text-right">
                            {h.qty.toLocaleString("en-US", { maximumFractionDigits: 4 })}
                          </td>
                          <td className="px-3 py-2 text-right">{formatIdr(h.avg_cost)}</td>
                          <td className="px-3 py-2 text-right">
                            {h.latest_close === null ? "—" : formatIdr(h.latest_close)}
                          </td>
                          <td className="px-3 py-2 text-right">{formatIdr(h.market_value)}</td>
                          <td className={`px-3 py-2 text-right ${plClass(h.unrealized_pl)}`}>
                            {formatIdr(h.unrealized_pl)}
                          </td>
                          <td className={`px-3 py-2 text-right ${plClass(h.unrealized_pl)}`}>
                            {formatPct(h.unrealized_pl_pct)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>

            <section className="grid gap-6 md:grid-cols-2">
              <AllocationBlock
                title="By sector"
                rows={summary.allocation_by_sector.map((row) => ({
                  label: row.sector ?? "Unclassified",
                  value: row.value,
                  pct: row.pct,
                }))}
              />
              <AllocationBlock
                title="By symbol"
                rows={summary.allocation_by_symbol.map((row) => ({
                  label: row.symbol ?? "—",
                  value: row.value,
                  pct: row.pct,
                }))}
              />
            </section>
          </>
        ) : !error ? (
          <p className="text-sm text-muted-foreground">
            {loading ? "Loading summary…" : "Select a portfolio to see its summary."}
          </p>
        ) : null}
      </CardContent>
    </Card>
  )
}

type StatProps = {
  label: string
  value: string
  emphasize?: boolean
  tone?: number
}

function CashLine({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className={value < 0 ? "text-rose-600" : undefined}>{formatIdr(value)}</span>
    </div>
  )
}

function Stat({ label, value, emphasize, tone }: StatProps) {
  const toneClass =
    tone === undefined
      ? ""
      : tone > 0
        ? "text-emerald-600"
        : tone < 0
          ? "text-rose-600"
          : ""
  return (
    <div className="space-y-1">
      <p className="text-xs uppercase text-muted-foreground">{label}</p>
      <p
        className={`text-base font-semibold ${emphasize ? "text-foreground" : ""} ${toneClass}`}
      >
        {value}
      </p>
    </div>
  )
}

type AllocationBlockProps = {
  title: string
  rows: { label: string; value: number; pct: number | null }[]
}

function AllocationBlock({ title, rows }: AllocationBlockProps) {
  if (rows.length === 0) {
    return (
      <div>
        <h3 className="mb-2 text-sm font-medium text-muted-foreground">{title}</h3>
        <p className="rounded border border-dashed bg-muted/30 px-3 py-4 text-center text-sm text-muted-foreground">
          No allocation data yet.
        </p>
      </div>
    )
  }

  const max = Math.max(...rows.map((r) => r.value), 1)
  return (
    <div>
      <h3 className="mb-2 text-sm font-medium text-muted-foreground">{title}</h3>
      <ul className="space-y-2">
        {rows.map((row) => (
          <li key={row.label} className="space-y-1">
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span className="truncate font-medium">{row.label}</span>
              <span className="tabular-nums text-muted-foreground">
                {formatIdr(row.value)}
                {row.pct !== null ? ` · ${row.pct.toFixed(2)}%` : ""}
              </span>
            </div>
            <div className="h-1.5 rounded bg-muted">
              <div
                className="h-full rounded bg-foreground/70"
                style={{ width: `${(row.value / max) * 100}%` }}
                aria-hidden
              />
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
