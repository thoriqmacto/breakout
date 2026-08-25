"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { useParams, useRouter } from "next/navigation"
import { ArrowLeft, Copy, Loader2, Pencil, Play, Trash2 } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { StrategyForm } from "@/components/strategy-form"
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
  deleteStrategy,
  fetchRunMatches,
  fetchRuns,
  fetchStrategy,
  runStrategy,
  type StrategyMatch,
  type StrategyRecord,
  type StrategyRun,
} from "@/lib/strategy-builder-client"

export default function StrategyDetailPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const { accessToken } = useAuth()

  const id = Number(params?.id)

  const [strategy, setStrategy] = useState<StrategyRecord | null>(null)
  const [runs, setRuns] = useState<StrategyRun[]>([])
  const [matches, setMatches] = useState<StrategyMatch[]>([])
  const [selectedRun, setSelectedRun] = useState<StrategyRun | null>(null)
  const [editing, setEditing] = useState(false)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!accessToken || !Number.isFinite(id)) return

    setLoading(true)
    setError(null)

    try {
      const [record, history] = await Promise.all([
        fetchStrategy(accessToken, id),
        fetchRuns(accessToken, id),
      ])

      setStrategy(record)
      setRuns(history)

      // Show the newest completed run's matches by default.
      const newest = history.find((run) => run.status === "completed")

      if (newest) {
        const detail = await fetchRunMatches(accessToken, id, newest.id)
        setSelectedRun(detail.run)
        setMatches(detail.matches)
      } else {
        setSelectedRun(null)
        setMatches([])
      }
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to load the strategy.")
    } finally {
      setLoading(false)
    }
  }, [accessToken, id])

  useEffect(() => {
    void load()
  }, [load])

  const showRun = async (run: StrategyRun) => {
    if (!accessToken) return

    try {
      const detail = await fetchRunMatches(accessToken, id, run.id)
      setSelectedRun(detail.run)
      setMatches(detail.matches)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to load run results.")
    }
  }

  const handleRun = async () => {
    if (!accessToken) return

    setBusy(true)
    setError(null)

    try {
      await runStrategy(accessToken, id)
      await load()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to queue the run.")
    } finally {
      setBusy(false)
    }
  }

  const handleCopy = async () => {
    if (!accessToken) return

    setBusy(true)
    try {
      const copy = await copyStrategy(accessToken, id)
      router.push(`/dashboard/strategy/${copy.id}`)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to copy the strategy.")
      setBusy(false)
    }
  }

  const handleDelete = async () => {
    if (!accessToken || !window.confirm("Delete this strategy and its run history?")) return

    setBusy(true)
    try {
      await deleteStrategy(accessToken, id)
      router.push("/dashboard/strategy")
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to delete the strategy.")
      setBusy(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center gap-2 py-16 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" /> Loading…
      </div>
    )
  }

  if (!strategy) {
    return (
      <div className="space-y-4">
        <Link href="/dashboard/strategy">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="mr-1 h-4 w-4" /> Strategies
          </Button>
        </Link>
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error ?? "Strategy not found."}
        </div>
      </div>
    )
  }

  if (editing) {
    return (
      <div className="space-y-6">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="sm" onClick={() => setEditing(false)}>
            <ArrowLeft className="mr-1 h-4 w-4" /> Back
          </Button>
          <h1 className="text-2xl font-semibold">Edit {strategy.name}</h1>
        </div>
        <StrategyForm strategy={strategy} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-start gap-3">
          <Link href="/dashboard/strategy">
            <Button variant="ghost" size="sm">
              <ArrowLeft className="mr-1 h-4 w-4" /> Strategies
            </Button>
          </Link>
          <div>
            <h1 className="text-2xl font-semibold">{strategy.name}</h1>
            <p className="text-sm text-muted-foreground">
              {strategy.visibility}
              {strategy.is_owner ? " · yours" : ` · by ${strategy.owner?.name ?? "unknown"}`}
              {strategy.copied_from_id ? " · copied" : ""}
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button size="sm" onClick={() => void handleRun()} disabled={busy}>
            {busy ? (
              <Loader2 className="mr-1 h-4 w-4 animate-spin" />
            ) : (
              <Play className="mr-1 h-4 w-4" />
            )}
            Run
          </Button>

          {strategy.is_owner ? (
            <>
              <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                <Pencil className="mr-1 h-4 w-4" /> Edit
              </Button>
              <Button variant="outline" size="sm" onClick={() => void handleDelete()} disabled={busy}>
                <Trash2 className="mr-1 h-4 w-4" /> Delete
              </Button>
            </>
          ) : (
            <Button variant="outline" size="sm" onClick={() => void handleCopy()} disabled={busy}>
              <Copy className="mr-1 h-4 w-4" /> Copy to edit
            </Button>
          )}
        </div>
      </div>

      {strategy.description ? (
        <p className="text-sm text-muted-foreground">{strategy.description}</p>
      ) : null}

      {error ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[280px_1fr]">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Runs</CardTitle>
            <CardDescription>Newest first.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-1">
            {runs.length === 0 ? (
              <p className="py-2 text-sm text-muted-foreground">
                No runs yet. Use Run to queue one.
              </p>
            ) : (
              runs.map((run) => (
                <button
                  key={run.id}
                  type="button"
                  onClick={() => void showRun(run)}
                  className={`flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted ${
                    selectedRun?.id === run.id ? "bg-muted" : ""
                  }`}
                >
                  <span>{run.scan_date}</span>
                  <span className="text-xs text-muted-foreground">
                    {run.status === "completed" ? `${run.matched_count} matched` : run.status}
                  </span>
                </button>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {selectedRun ? `Matches for ${selectedRun.scan_date}` : "Matches"}
            </CardTitle>
            <CardDescription>
              {selectedRun
                ? `${selectedRun.matched_count} of ${selectedRun.evaluated_count} symbols matched.`
                : "Select a completed run to see its matches."}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {selectedRun?.status === "failed" ? (
              <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {selectedRun.error ?? "The run failed."}
              </div>
            ) : matches.length === 0 ? (
              <p className="py-4 text-sm text-muted-foreground">
                {selectedRun
                  ? "No symbols matched these rules on that date."
                  : "Nothing selected."}
              </p>
            ) : (
              <div className="space-y-3">
                {matches.map((match) => (
                  <div key={match.symbol} className="rounded-md border p-3">
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="font-medium">{match.symbol}</span>
                      <span className="text-xs text-muted-foreground">
                        {match.asset?.sector ?? ""}
                      </span>
                    </div>

                    {/* The trace is why this symbol matched, kept from the run. */}
                    <ul className="mt-2 space-y-1">
                      {match.explanation.map((entry, index) => (
                        <li key={index} className="flex items-center gap-2 text-xs">
                          <span
                            className={
                              entry.passed
                                ? "text-emerald-600"
                                : "text-muted-foreground line-through"
                            }
                          >
                            {entry.label}
                          </span>
                          <span className="text-muted-foreground">
                            {String(entry.actual ?? "—")}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
