"use client"

import { useCallback, useEffect, useState } from "react"
import { BellOff, BellRing, Loader2, Plus } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import { fetchBuiltInStrategies, type BuiltInStrategy } from "@/lib/strategy-builder-client"

/**
 * Ask to be told when a strategy fires on this asset.
 *
 * The evaluation runs after the evening collection and uses the same strategy
 * classes the backtester walks history with, so a subscription made here
 * cannot mean something different from the backtest that justified it.
 */

type StrategyAlert = {
  id: number
  symbol: string
  strategy: string
  signal: string
  enabled: boolean
  last_signal: string | null
  last_triggered_on: string | null
}

export function AssetAlertsCard({ assetId, symbol }: { assetId: number; symbol: string }) {
  const { accessToken } = useAuth()

  const [alerts, setAlerts] = useState<StrategyAlert[]>([])
  const [strategies, setStrategies] = useState<BuiltInStrategy[]>([])
  const [strategy, setStrategy] = useState("")
  const [busy, setBusy] = useState(false)
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

    try {
      const data = await request<{ alerts: StrategyAlert[] }>(
        `/v1/strategy-alerts?asset_id=${assetId}`,
      )
      setAlerts(data.alerts)
    } catch {
      setAlerts([])
    }
  }, [accessToken, assetId, request])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (!accessToken) return

    void fetchBuiltInStrategies(accessToken)
      .then((payload) => {
        // A strategy that emits no daily signal can never fire on a daily bar,
        // so offering it would promise a reminder that never arrives.
        const daily = payload.built_in.filter((item) => item.emits_daily_signal !== false)
        setStrategies(daily)
        if (daily.length > 0) setStrategy((current) => current || daily[0].key)
      })
      .catch(() => setStrategies([]))
  }, [accessToken])

  const add = async () => {
    if (!accessToken || strategy === "") return

    setBusy(true)
    setError(null)

    try {
      await request("/v1/strategy-alerts", {
        method: "POST",
        json: { asset_id: assetId, strategy, signal: "buy" },
      })
      await load()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to add the alert.")
    } finally {
      setBusy(false)
    }
  }

  const toggle = async (alert: StrategyAlert) => {
    if (!accessToken) return

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

  const remove = async (alert: StrategyAlert) => {
    if (!accessToken) return

    try {
      await request(`/v1/strategy-alerts/${alert.id}`, { method: "DELETE" })
      await load()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to remove the alert.")
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-2">
        <label className="space-y-1 text-sm">
          <span className="text-xs uppercase text-muted-foreground">Strategy</span>
          <select
            value={strategy}
            onChange={(event) => setStrategy(event.target.value)}
            className="h-9 w-52 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
          >
            {strategies.map((option) => (
              <option key={option.key} value={option.key}>
                {option.name ?? option.key}
              </option>
            ))}
          </select>
        </label>

        <Button size="sm" onClick={() => void add()} disabled={busy || strategy === ""}>
          {busy ? (
            <Loader2 className="mr-1 h-3 w-3 animate-spin" />
          ) : (
            <Plus className="mr-1 h-3 w-3" />
          )}
          Alert me on buy
        </Button>
      </div>

      {error ? (
        <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </p>
      ) : null}

      {alerts.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No alerts for {symbol}. An alert is checked after the evening collection, so a signal you
          see here is for the next session.
        </p>
      ) : (
        <ul className="space-y-2">
          {alerts.map((alert) => (
            <li
              key={alert.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
            >
              <div className="min-w-0">
                <p className="font-medium">
                  {alert.strategy} · {alert.signal}
                </p>
                <p className="text-xs text-muted-foreground">
                  {alert.last_triggered_on
                    ? `last fired ${alert.last_triggered_on}`
                    : "not fired yet"}
                  {alert.last_signal ? ` · currently ${alert.last_signal}` : ""}
                  {alert.enabled ? "" : " · disabled"}
                </p>
              </div>

              <div className="flex items-center gap-1">
                <Button variant="ghost" size="sm" onClick={() => void toggle(alert)}>
                  {alert.enabled ? (
                    <BellOff className="mr-1 h-3 w-3" />
                  ) : (
                    <BellRing className="mr-1 h-3 w-3" />
                  )}
                  {alert.enabled ? "Mute" : "Enable"}
                </Button>
                <Button variant="ghost" size="sm" onClick={() => void remove(alert)}>
                  Remove
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
