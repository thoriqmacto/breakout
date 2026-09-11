"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { BellOff, BellRing, Loader2 } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

/**
 * Which alerts are armed, and exactly what the nightly job does with them.
 *
 * The mechanism was only written down in a commit message and a config
 * description, so the obvious question -- does an alert need a backtest first?
 * -- had no answer on screen. It does not, and the two are independent on
 * purpose: a backtest is how you decide a strategy is worth watching, and an
 * alert is a standing question about the newest bar. Saying so here is
 * cheaper than the wrong assumption.
 */

type StrategyAlert = {
  id: number
  symbol: string
  strategy: string
  signal: string
  enabled: boolean
  last_signal: string | null
  last_triggered_on: string | null
  last_evaluated_at: string | null
}

export function ArmedAlerts() {
  const { accessToken } = useAuth()

  const [alerts, setAlerts] = useState<StrategyAlert[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const request = useCallback(
    async <T,>(path: string, init: RequestInit & { json?: unknown } = {}): Promise<T> => {
      const { json, ...rest } = init

      const response = await fetch(buildApiUrl(path), {
        ...rest,
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${accessToken}`,
          ...(json !== undefined ? { "Content-Type": "application/json" } : {}),
        },
        ...(json !== undefined ? { body: JSON.stringify(json) } : {}),
      })

      const payload = await parseJson<ApiResponse<T>>(response)

      if (!response.ok || !payload || payload.status !== "success") {
        const message =
          payload && "message" in payload && typeof payload.message === "string"
            ? payload.message
            : "Request failed."

        throw new Error(message)
      }

      return payload.data as T
    },
    [accessToken],
  )

  const load = useCallback(async () => {
    if (!accessToken) return

    setLoading(true)

    try {
      const data = await request<{ alerts: StrategyAlert[] }>("/v1/strategy-alerts")
      setAlerts(data.alerts)
      setError(null)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to load alerts.")
    } finally {
      setLoading(false)
    }
  }, [accessToken, request])

  useEffect(() => {
    void load()
  }, [load])

  const toggle = async (alert: StrategyAlert) => {
    try {
      await request(`/v1/strategy-alerts/${alert.id}`, {
        method: "PATCH",
        json: { enabled: !alert.enabled },
      })
      await load()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to update the alert.")
    }
  }

  const armed = alerts.filter((alert) => alert.enabled)

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">What the nightly check does</CardTitle>
          <CardDescription>
            One scheduled task, <code className="rounded bg-muted px-1 py-0.5">Strategy Alerts</code>,
            at 18:30 WIB — after the 18:00 collection and the analysis refresh, so it reads the bars
            that landed tonight rather than yesterday&apos;s.
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-3 text-sm">
          <ol className="list-decimal space-y-1 pl-5">
            <li>Take every alert below that is <strong>armed</strong>. Muted ones are skipped entirely.</li>
            <li>
              Load that asset&apos;s full price history and build the strategy on the{" "}
              <strong>newest bar</strong> — the same strategy class the backtester walks history
              with, pointed at today instead of at the past.
            </li>
            <li>
              Ask it for a signal. If it matches the side you armed (buy or sell) and it has not
              already fired for that same session, raise a dashboard reminder.
            </li>
            <li>
              Record the bar&apos;s date against the alert, so a re-run the same evening recognises
              the signal as the same event rather than a new one.
            </li>
          </ol>

          <div className="rounded-md border border-amber-500/40 bg-amber-500/5 p-3">
            <p className="font-medium">Alerts do not depend on backtest records.</p>
            <p className="mt-1 text-muted-foreground">
              Any asset with at least two price bars can be watched, whether or not it has ever been
              backtested. The two are independent on purpose: a backtest is how you decide a strategy
              is worth watching on a symbol, and the alert is the standing question about the newest
              bar. Because both evaluate the same strategy code, an alert cannot mean something
              different from the backtest that justified it.
            </p>
          </div>

          <p className="text-muted-foreground">
            A signal is for the <strong>next</strong> session — the bar it fired on has already
            closed. It is the strategy firing, not a recommendation: the position, the size and the
            stop are still yours to decide.
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            Armed alerts {armed.length > 0 ? `(${armed.length})` : ""}
          </CardTitle>
          <CardDescription>
            Add one from an asset page — open Assets, click a symbol, then the Strategy alerts card.
          </CardDescription>
        </CardHeader>

        <CardContent>
          {error ? (
            <p className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              {error}
            </p>
          ) : null}

          {loading ? (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" /> Loading alerts…
            </p>
          ) : alerts.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Nothing is being watched yet, so tonight&apos;s 18:30 run will report{" "}
              <code className="rounded bg-muted px-1 py-0.5">0 evaluated, 0 fired</code> — a healthy
              result, not an idle one.{" "}
              <Link href="/dashboard/assets" className="font-medium underline">
                Pick an asset
              </Link>{" "}
              to arm the first.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-3">Symbol</th>
                    <th className="py-2 pr-3">Strategy</th>
                    <th className="py-2 pr-3">Watching for</th>
                    <th className="py-2 pr-3">Currently reads</th>
                    <th className="py-2 pr-3">Last fired</th>
                    <th className="py-2 pr-3">Last checked</th>
                    <th className="py-2" />
                  </tr>
                </thead>
                <tbody>
                  {alerts.map((alert) => (
                    <tr key={alert.id} className={`border-t ${alert.enabled ? "" : "opacity-60"}`}>
                      <td className="py-2 pr-3 font-medium">{alert.symbol}</td>
                      <td className="py-2 pr-3">{alert.strategy}</td>
                      <td className="py-2 pr-3 uppercase">{alert.signal}</td>
                      <td className="py-2 pr-3">
                        {alert.last_signal ? (
                          <span
                            className={
                              alert.last_signal === alert.signal
                                ? "font-medium text-emerald-600 dark:text-emerald-400"
                                : "text-muted-foreground"
                            }
                          >
                            {alert.last_signal}
                          </span>
                        ) : (
                          <span className="text-muted-foreground">not checked yet</span>
                        )}
                      </td>
                      <td className="py-2 pr-3 text-muted-foreground">
                        {alert.last_triggered_on ?? "never"}
                      </td>
                      <td className="py-2 pr-3 text-muted-foreground">
                        {alert.last_evaluated_at
                          ? alert.last_evaluated_at.slice(0, 10)
                          : "—"}
                      </td>
                      <td className="py-2 text-right">
                        <Button variant="ghost" size="sm" onClick={() => void toggle(alert)}>
                          {alert.enabled ? (
                            <>
                              <BellOff className="mr-1 h-3 w-3" /> Mute
                            </>
                          ) : (
                            <>
                              <BellRing className="mr-1 h-3 w-3" /> Arm
                            </>
                          )}
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>

              <p className="mt-3 text-xs text-muted-foreground">
                <strong>Currently reads</strong> is the signal at the last evaluation, whether or not
                it matched. It is how you tell &quot;watching, nothing happening&quot; from
                &quot;never evaluated&quot; — a blank here means the nightly job has not reached this
                alert yet.
              </p>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
