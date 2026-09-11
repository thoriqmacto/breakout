"use client"

import { useCallback, useEffect, useState } from "react"
import { Loader2, Play } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import {
  fetchBacktest,
  fetchBacktests,
  formatStat,
  runBacktest,
  type BacktestRun,
  type BuiltInStrategyOption,
} from "@/lib/backtest-client"

/**
 * Run a backtest, see what it did, change something, run it again.
 *
 * The CLI could already do this; what it could not do was leave the result
 * anywhere. Every run here is stored, so the history below is the same table
 * `asset:backtest` now writes to and the comparison tab reads from -- one set
 * of rows rather than three views computing their own.
 */
export function BacktestPanel({
  strategies,
}: {
  strategies: BuiltInStrategyOption[]
}) {
  const { accessToken } = useAuth()

  const [symbol, setSymbol] = useState("")
  const [strategy, setStrategy] = useState("")
  const [capital, setCapital] = useState("3000000")
  const [from, setFrom] = useState("")
  const [to, setTo] = useState("")

  const [running, setRunning] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<BacktestRun | null>(null)
  const [history, setHistory] = useState<BacktestRun[]>([])

  useEffect(() => {
    if (strategy === "" && strategies.length > 0) {
      setStrategy(strategies[0].key)
    }
  }, [strategies, strategy])

  const loadHistory = useCallback(async () => {
    if (!accessToken) return

    try {
      setHistory(await fetchBacktests(accessToken, { limit: 20 }))
    } catch {
      // History is context, not the answer: failing to load it must not
      // replace a result the user just waited for.
      setHistory([])
    }
  }, [accessToken])

  useEffect(() => {
    void loadHistory()
  }, [loadHistory])

  const handleRun = async () => {
    if (!accessToken) return

    const trimmed = symbol.trim().toUpperCase()

    if (trimmed === "") {
      setError("A symbol is required.")
      return
    }

    setRunning(true)
    setError(null)

    try {
      const payload = await runBacktest(accessToken, {
        symbol: trimmed,
        strategy,
        capital: Number(capital) || undefined,
        from: from || null,
        to: to || null,
      })

      setResult(payload.run ?? null)
      await loadHistory()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to run the backtest.")
    } finally {
      setRunning(false)
    }
  }

  const openRun = async (runId: string) => {
    if (!accessToken) return

    try {
      const { run } = await fetchBacktest(accessToken, runId)
      setResult(run)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to load that backtest.")
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Run a backtest</CardTitle>
          <CardDescription>
            The same run <code className="rounded bg-muted px-1 py-0.5">asset:backtest</code>{" "}
            performs, from the same code. Every run is stored, including one that finds no trades —
            a strategy that did nothing for two years is a result worth keeping.
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <Field label="Symbol">
              <Input
                value={symbol}
                onChange={(event) => setSymbol(event.target.value)}
                placeholder="BBRI"
                autoCapitalize="characters"
              />
            </Field>

            <Field label="Strategy">
              <select
                value={strategy}
                onChange={(event) => setStrategy(event.target.value)}
                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
              >
                {strategies.map((option) => (
                  <option key={option.key} value={option.key}>
                    {option.name ?? option.label ?? option.key}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="Capital">
              <Input
                value={capital}
                onChange={(event) => setCapital(event.target.value)}
                inputMode="numeric"
              />
            </Field>

            <Field label="From">
              <Input type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
            </Field>

            <Field label="To">
              <Input type="date" value={to} onChange={(event) => setTo(event.target.value)} />
            </Field>
          </div>

          <div className="flex items-center gap-2">
            <Button size="sm" onClick={() => void handleRun()} disabled={running}>
              {running ? (
                <Loader2 className="mr-1 h-3 w-3 animate-spin" />
              ) : (
                <Play className="mr-1 h-3 w-3" />
              )}
              Run backtest
            </Button>
            <span className="text-xs text-muted-foreground">
              Leave the dates empty to use the whole history.
            </span>
          </div>

          {error ? (
            <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              {error}
            </p>
          ) : null}
        </CardContent>
      </Card>

      {result ? <BacktestResult run={result} /> : null}

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Recent runs</CardTitle>
          <CardDescription>
            Runs started here and from the command line, newest first.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {history.length === 0 ? (
            <p className="text-sm text-muted-foreground">No backtests have been stored yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-3">Symbol</th>
                    <th className="py-2 pr-3">Strategy</th>
                    <th className="py-2 pr-3 text-right">Return</th>
                    <th className="py-2 pr-3 text-right">Trades</th>
                    <th className="py-2 pr-3">Source</th>
                    <th className="py-2 pr-3">Run at</th>
                    <th className="py-2" />
                  </tr>
                </thead>
                <tbody>
                  {history.map((run) => (
                    <tr key={run.run_id} className="border-t">
                      <td className="py-2 pr-3 font-medium">{run.symbol ?? "—"}</td>
                      <td className="py-2 pr-3">{run.strategy ?? "—"}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">
                        {run.stats?.return_pct === null || run.stats?.return_pct === undefined
                          ? "—"
                          : `${run.stats.return_pct.toFixed(1)}%`}
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">
                        {formatStat(run.stats, "trades", "count")}
                      </td>
                      <td className="py-2 pr-3 text-muted-foreground">{run.source ?? "—"}</td>
                      <td className="py-2 pr-3 text-muted-foreground">
                        {run.created_at ? run.created_at.slice(0, 16).replace("T", " ") : "—"}
                      </td>
                      <td className="py-2 text-right">
                        <Button variant="ghost" size="sm" onClick={() => void openRun(run.run_id)}>
                          Open
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

function BacktestResult({ run }: { run: BacktestRun }) {
  const trades = run.trades ?? []

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {run.symbol} · {run.strategy}
        </CardTitle>
        <CardDescription>
          {run.params?.bars ?? "?"} bars, {run.params?.from ?? "?"} → {run.params?.to ?? "?"}
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <Stat label="Return" value={
            run.stats?.return_pct === null || run.stats?.return_pct === undefined
              ? "—"
              : `${run.stats.return_pct.toFixed(1)}%`
          } />
          <Stat label="CAGR" value={formatStat(run.stats, "cagr", "percent")} />
          <Stat label="Max DD" value={formatStat(run.stats, "maxdd", "percent")} />
          <Stat label="Sharpe" value={formatStat(run.stats, "sharpe")} />
          <Stat label="Win rate" value={formatStat(run.stats, "winrate", "percent")} />
          <Stat label="Profit factor" value={formatStat(run.stats, "profit_factor")} />
        </div>

        {trades.length === 0 ? (
          <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
            This strategy took no trades over that range. That is a result, not a failure to run —
            the run is stored and appears in the comparison.
          </p>
        ) : (
          <div className="max-h-80 overflow-auto rounded-md border">
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="px-3 py-2">Entry</th>
                  <th className="px-3 py-2">Exit</th>
                  <th className="px-3 py-2 text-right">Entry px</th>
                  <th className="px-3 py-2 text-right">Exit px</th>
                  <th className="px-3 py-2 text-right">Units</th>
                  <th className="px-3 py-2 text-right">P&amp;L</th>
                </tr>
              </thead>
              <tbody>
                {trades.map((trade, index) => (
                  <tr key={`${trade.entry_date}-${index}`} className="border-t">
                    <td className="px-3 py-1.5 tabular-nums">{trade.entry_date ?? "—"}</td>
                    <td className="px-3 py-1.5 tabular-nums">{trade.exit_date ?? "open"}</td>
                    <td className="px-3 py-1.5 text-right tabular-nums">
                      {trade.entry_px.toFixed(2)}
                    </td>
                    <td className="px-3 py-1.5 text-right tabular-nums">
                      {trade.exit_px === null ? "—" : trade.exit_px.toFixed(2)}
                    </td>
                    <td className="px-3 py-1.5 text-right tabular-nums">{trade.units}</td>
                    <td
                      className={`px-3 py-1.5 text-right tabular-nums ${
                        (trade.pnl ?? 0) >= 0 ? "text-emerald-600" : "text-red-600"
                      }`}
                    >
                      {trade.pnl === null ? "—" : trade.pnl.toFixed(0)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="space-y-1 text-sm">
      <span className="text-xs uppercase text-muted-foreground">{label}</span>
      {children}
    </label>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border bg-muted/30 p-2">
      <p className="text-xs uppercase text-muted-foreground">{label}</p>
      <p className="mt-0.5 font-medium tabular-nums">{value}</p>
    </div>
  )
}
