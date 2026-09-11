"use client"

import { useCallback, useState } from "react"
import { Loader2, Play, Search } from "lucide-react"

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
  fetchStrategyComparison,
  formatStat,
  runBacktest,
  type StrategyComparisonRow,
} from "@/lib/backtest-client"

/**
 * Every strategy against one asset, side by side.
 *
 * Built from stored runs rather than by running anything: a comparison that
 * re-ran on every page load would be slow, and would quietly answer a
 * different question -- "how would these do today" rather than "how did these
 * do when I ran them". A strategy that has never been run for this symbol is
 * shown as never run, not as a row of zeroes, because a strategy that lost
 * nothing and a strategy that was never tried are not the same claim.
 */
export function StrategyComparison() {
  const { accessToken } = useAuth()

  const [symbol, setSymbol] = useState("")
  const [loadedSymbol, setLoadedSymbol] = useState<string | null>(null)
  const [rows, setRows] = useState<StrategyComparisonRow[]>([])
  const [loading, setLoading] = useState(false)
  const [running, setRunning] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(
    async (target: string) => {
      if (!accessToken) return

      setLoading(true)
      setError(null)

      try {
        const payload = await fetchStrategyComparison(accessToken, target)
        setRows(payload.strategies)
        setLoadedSymbol(payload.symbol)
      } catch (cause) {
        setError(cause instanceof Error ? cause.message : "Unable to load the comparison.")
        setRows([])
      } finally {
        setLoading(false)
      }
    },
    [accessToken],
  )

  const handleLoad = () => {
    const trimmed = symbol.trim().toUpperCase()

    if (trimmed === "") {
      setError("A symbol is required.")
      return
    }

    void load(trimmed)
  }

  const handleRunAll = async () => {
    if (!accessToken) return

    const target = (loadedSymbol ?? symbol).trim().toUpperCase()

    if (target === "") {
      setError("A symbol is required.")
      return
    }

    setRunning(true)
    setError(null)

    try {
      await runBacktest(accessToken, { symbol: target, compare: true })
      await load(target)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to run the comparison.")
    } finally {
      setRunning(false)
    }
  }

  const ran = rows.filter((row) => row.run !== null)

  // Ranked by return, and only among strategies that were actually run: a best
  // picked from a set including never-run strategies would be a best among
  // blanks.
  const best = ran.reduce<StrategyComparisonRow | null>((winner, row) => {
    const current = row.run?.stats?.return_pct ?? null
    const leader = winner?.run?.stats?.return_pct ?? null

    if (current === null) return winner
    if (leader === null) return row

    return current > leader ? row : winner
  }, null)

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Compare strategies on one asset</CardTitle>
          <CardDescription>
            The latest stored run for each strategy. Nothing is recomputed here — what you see is
            what was run, with the parameters it was run with.
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-3">
          <div className="flex flex-wrap items-end gap-2">
            <label className="space-y-1 text-sm">
              <span className="text-xs uppercase text-muted-foreground">Symbol</span>
              <Input
                value={symbol}
                onChange={(event) => setSymbol(event.target.value)}
                placeholder="BBRI"
                className="w-40"
                onKeyDown={(event) => {
                  if (event.key === "Enter") handleLoad()
                }}
              />
            </label>

            <Button size="sm" variant="outline" onClick={handleLoad} disabled={loading}>
              {loading ? (
                <Loader2 className="mr-1 h-3 w-3 animate-spin" />
              ) : (
                <Search className="mr-1 h-3 w-3" />
              )}
              Load
            </Button>

            <Button size="sm" onClick={() => void handleRunAll()} disabled={running}>
              {running ? (
                <Loader2 className="mr-1 h-3 w-3 animate-spin" />
              ) : (
                <Play className="mr-1 h-3 w-3" />
              )}
              Run every strategy
            </Button>
          </div>

          {error ? (
            <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              {error}
            </p>
          ) : null}
        </CardContent>
      </Card>

      {loadedSymbol ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">{loadedSymbol}</CardTitle>
            <CardDescription>
              {ran.length} of {rows.length} strategies have a stored run for this symbol.
            </CardDescription>
          </CardHeader>

          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-3">Strategy</th>
                    <th className="py-2 pr-3 text-right">Return</th>
                    <th className="py-2 pr-3 text-right">CAGR</th>
                    <th className="py-2 pr-3 text-right">Max DD</th>
                    <th className="py-2 pr-3 text-right">Sharpe</th>
                    <th className="py-2 pr-3 text-right">Win rate</th>
                    <th className="py-2 pr-3 text-right">Profit factor</th>
                    <th className="py-2 pr-3 text-right">Trades</th>
                    <th className="py-2 pr-3">Run at</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => {
                    const stats = row.run?.stats ?? null
                    const isBest = best !== null && best.strategy === row.strategy

                    return (
                      <tr
                        key={row.strategy}
                        className={`border-t ${isBest ? "bg-emerald-500/5" : ""}`}
                      >
                        <td className="py-2 pr-3 font-medium">
                          {row.label}
                          {isBest ? (
                            <span className="ml-2 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                              best return
                            </span>
                          ) : null}
                        </td>

                        {row.run === null ? (
                          <td className="py-2 pr-3 text-muted-foreground" colSpan={8}>
                            Never run for {loadedSymbol}
                          </td>
                        ) : (
                          <>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {stats?.return_pct === null || stats?.return_pct === undefined
                                ? "—"
                                : `${stats.return_pct.toFixed(1)}%`}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {formatStat(stats, "cagr", "percent")}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {formatStat(stats, "maxdd", "percent")}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {formatStat(stats, "sharpe")}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {formatStat(stats, "winrate", "percent")}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {formatStat(stats, "profit_factor")}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {formatStat(stats, "trades", "count")}
                            </td>
                            <td className="py-2 pr-3 text-muted-foreground">
                              {row.run.created_at
                                ? row.run.created_at.slice(0, 10)
                                : "—"}
                            </td>
                          </>
                        )}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <p className="mt-3 text-xs text-muted-foreground">
              Rows can come from runs made on different days with different parameters. Use{" "}
              <strong>Run every strategy</strong> to put them all on the same footing before
              reading the comparison as a ranking.
            </p>
          </CardContent>
        </Card>
      ) : null}
    </div>
  )
}
