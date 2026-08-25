"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { Copy, Loader2, Play, Plus, RefreshCcw } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { StrategyCards } from "@/components/strategy-cards"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import {
  copyStrategy,
  fetchStrategies,
  runStrategy,
  type StrategyRecord,
} from "@/lib/strategy-builder-client"

type Scope = "mine" | "public" | "all"

const SCOPES: { value: Scope; label: string; empty: string }[] = [
  { value: "mine", label: "My strategies", empty: "You have not created a strategy yet." },
  { value: "public", label: "Public", empty: "Nobody has shared a public strategy yet." },
  { value: "all", label: "All visible", empty: "Nothing to show yet." },
]

export default function StrategiesPage() {
  const { accessToken } = useAuth()
  const [scope, setScope] = useState<Scope>("mine")
  const [strategies, setStrategies] = useState<StrategyRecord[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!accessToken) return

    setLoading(true)
    setError(null)

    try {
      setStrategies(await fetchStrategies(accessToken, scope))
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to load strategies.")
    } finally {
      setLoading(false)
    }
  }, [accessToken, scope])

  useEffect(() => {
    void load()
  }, [load])

  const handleRun = async (strategy: StrategyRecord) => {
    if (!accessToken) return

    setBusyId(strategy.id)
    setNotice(null)
    setError(null)

    try {
      const run = await runStrategy(accessToken, strategy.id)
      setNotice(
        `Run queued for ${strategy.name} on ${run.scan_date}. Results appear once a queue worker picks it up.`,
      )
      await load()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to queue the run.")
    } finally {
      setBusyId(null)
    }
  }

  const handleCopy = async (strategy: StrategyRecord) => {
    if (!accessToken) return

    setBusyId(strategy.id)
    setNotice(null)
    setError(null)

    try {
      const copy = await copyStrategy(accessToken, strategy.id)
      setNotice(`Copied to "${copy.name}" in your strategies. Edit it there to suit your needs.`)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to copy the strategy.")
    } finally {
      setBusyId(null)
    }
  }

  const activeScope = SCOPES.find((s) => s.value === scope)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Strategies</h1>
          <p className="text-sm text-muted-foreground">
            Build rules over daily features and broker flow, then run them against a scan date.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => void load()} disabled={loading}>
            <RefreshCcw className={`mr-1 h-4 w-4 ${loading ? "animate-spin" : ""}`} />
            Refresh
          </Button>
          <Link href="/dashboard/strategy/new">
            <Button size="sm">
              <Plus className="mr-1 h-4 w-4" /> New strategy
            </Button>
          </Link>
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        {SCOPES.map((option) => (
          <Button
            key={option.value}
            variant={scope === option.value ? "default" : "outline"}
            size="sm"
            onClick={() => setScope(option.value)}
          >
            {option.label}
          </Button>
        ))}
      </div>

      {notice ? (
        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          {notice}
        </div>
      ) : null}

      {error ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      ) : null}

      {loading ? (
        <Card>
          <CardContent className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" /> Loading strategies…
          </CardContent>
        </Card>
      ) : (
        <>
          <StrategyCards strategies={strategies} emptyMessage={activeScope?.empty} />

          {strategies.length > 0 ? (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Actions</CardTitle>
                <CardDescription>
                  Running queues a background scan. A public strategy you do not own can be copied
                  into your own list and edited there.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-2">
                {strategies.map((strategy) => (
                  <div
                    key={strategy.id}
                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
                  >
                    <div className="min-w-0">
                      <Link
                        href={`/dashboard/strategy/${strategy.id}`}
                        className="text-sm font-medium hover:underline"
                      >
                        {strategy.name}
                      </Link>
                      <p className="text-xs text-muted-foreground">
                        {strategy.is_owner ? "yours" : `by ${strategy.owner?.name ?? "unknown"}`}
                        {" · "}
                        {strategy.runs_count ?? 0} run{(strategy.runs_count ?? 0) === 1 ? "" : "s"}
                      </p>
                    </div>

                    <div className="flex items-center gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={busyId === strategy.id}
                        onClick={() => void handleRun(strategy)}
                      >
                        {busyId === strategy.id ? (
                          <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                        ) : (
                          <Play className="mr-1 h-3 w-3" />
                        )}
                        Run
                      </Button>

                      {!strategy.is_owner ? (
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={busyId === strategy.id}
                          onClick={() => void handleCopy(strategy)}
                        >
                          <Copy className="mr-1 h-3 w-3" /> Copy
                        </Button>
                      ) : null}
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>
          ) : null}
        </>
      )}
    </div>
  )
}
