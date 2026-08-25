"use client"

import Link from "next/link"
import { CheckCircle2, Clock, Loader2, XCircle } from "lucide-react"

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import type { RunStatus, StrategyRecord } from "@/lib/strategy-builder-client"

function relativeTime(iso: string | null): string {
  if (!iso) return "never run"

  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return "never run"

  const seconds = Math.max(0, Math.floor((Date.now() - then) / 1000))
  if (seconds < 60) return "just now"

  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`

  return `${Math.floor(hours / 24)}d ago`
}

function StatusBadge({ status }: { status: RunStatus | null }) {
  if (status === null) {
    return <span className="text-xs text-muted-foreground">not run yet</span>
  }

  const map: Record<RunStatus, { icon: typeof CheckCircle2; className: string; label: string }> = {
    completed: { icon: CheckCircle2, className: "text-emerald-600", label: "completed" },
    failed: { icon: XCircle, className: "text-red-600", label: "failed" },
    running: { icon: Loader2, className: "text-blue-600", label: "running" },
    queued: { icon: Clock, className: "text-amber-600", label: "queued" },
  }

  const { icon: Icon, className, label } = map[status]

  return (
    <span className={`inline-flex items-center gap-1 text-xs font-medium ${className}`}>
      <Icon className={`h-3 w-3 ${status === "running" ? "animate-spin" : ""}`} />
      {label}
    </span>
  )
}

/**
 * One card per strategy, rendered from the columns the runner mirrors onto the
 * strategy row after each run, so the grid needs no per-card request.
 */
export function StrategyCards({
  strategies,
  emptyMessage = "No strategies yet.",
}: {
  strategies: StrategyRecord[]
  emptyMessage?: string
}) {
  if (strategies.length === 0) {
    return (
      <Card>
        <CardContent className="py-8 text-center text-sm text-muted-foreground">
          {emptyMessage}
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {strategies.map((strategy) => (
        <Card key={strategy.id} className="flex flex-col">
          <CardHeader className="pb-3">
            <div className="flex items-start justify-between gap-2">
              <CardTitle className="text-base font-semibold">
                <Link href={`/dashboard/strategy/${strategy.id}`} className="hover:underline">
                  {strategy.name}
                </Link>
              </CardTitle>
              <span
                className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium uppercase ${
                  strategy.visibility === "public"
                    ? "bg-blue-100 text-blue-700"
                    : "bg-muted text-muted-foreground"
                }`}
              >
                {strategy.visibility}
              </span>
            </div>
            {strategy.description ? (
              <CardDescription className="line-clamp-2">{strategy.description}</CardDescription>
            ) : null}
          </CardHeader>

          <CardContent className="mt-auto space-y-2">
            <div className="flex items-baseline gap-2">
              <span className="text-3xl font-semibold tabular-nums">
                {strategy.last_match_count ?? "—"}
              </span>
              <span className="text-xs text-muted-foreground">
                {strategy.last_match_count === 1 ? "match" : "matches"}
              </span>
            </div>

            <div className="flex items-center justify-between">
              <StatusBadge status={strategy.last_run_status} />
              <span className="text-xs text-muted-foreground">
                {relativeTime(strategy.last_run_at)}
              </span>
            </div>

            {!strategy.is_owner && strategy.owner ? (
              <p className="text-xs text-muted-foreground">by {strategy.owner.name}</p>
            ) : null}
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
